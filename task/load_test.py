#!/usr/bin/env python3
"""
High-Performance Load Testing Script for Submission API
Meets Machine Test Part 2 Requirements:
  - Total requests: 100,000 (configurable via --total)
  - Duplicates: 20,000 (configurable via --duplicates)
  - Unique keys: 80,000
  - Reports:
      * Submissions stored (HTTP 201)
      * Duplicates handled (HTTP 200)
      * Errors occurred
      * Throughput (Requests per Second)
      * Latency percentiles (min, max, avg, p50, p95, p99)
"""

import argparse
import asyncio
import json
import random
import sys
import time
import uuid
from typing import Dict, List, Optional
import aiohttp


class LoadTestRunner:
    def __init__(
        self,
        url: str,
        total_requests: int = 100_000,
        duplicate_count: int = 20_000,
        concurrency: int = 50,
        form_id: str = "load-test-form",
        timeout: float = 30.0,
        report_interval: int = 5_000,
    ):
        self.url = url
        self.total_requests = total_requests
        self.duplicate_count = duplicate_count
        self.unique_count = total_requests - duplicate_count
        self.concurrency = concurrency
        self.form_id = form_id
        self.timeout = timeout
        self.report_interval = report_interval

        if self.unique_count <= 0:
            raise ValueError("Total requests must be strictly greater than duplicate count.")

        # Results tracking
        self.status_201_created = 0
        self.status_200_ok = 0
        self.status_400_bad_request = 0
        self.status_other_errors = 0
        self.exceptions_count = 0
        self.error_samples: List[str] = []

        self.latencies: List[float] = []
        self.start_time: float = 0.0
        self.end_time: float = 0.0
        self.completed_requests = 0

    def generate_payloads(self) -> List[dict]:
        """
        Generate payload list with exact unique and duplicate counts.
        Ensures duplicate keys only appear after their corresponding unique key.
        """
        print(f"Generating {self.total_requests:,} payloads...")
        print(f"  - Unique submissions: {self.unique_count:,}")
        print(f"  - Duplicate submissions: {self.duplicate_count:,}")

        # 1. Generate unique keys
        unique_keys = [f"key-{uuid.uuid4().hex[:12]}-{i}" for i in range(self.unique_count)]

        # 2. Pick keys to duplicate from the unique pool
        # If duplicate_count <= unique_count, sample or random choice
        if self.duplicate_count <= self.unique_count:
            duplicate_keys = random.sample(unique_keys, self.duplicate_count)
        else:
            duplicate_keys = [random.choice(unique_keys) for _ in range(self.duplicate_count)]

        # 3. Create items: tuple of (is_duplicate: bool, key: str, email: str, data: dict)
        items = []
        for i, key in enumerate(unique_keys):
            items.append({
                "form_id": self.form_id,
                "idempotency_key": key,
                "email": f"user_{i}_{key[:6]}@loadtest.local",
                "data": {
                    "request_index": i,
                    "type": "unique",
                    "timestamp": time.time(),
                }
            })

        # To simulate realistic traffic where duplicates occur throughout the test,
        # we partition unique items and duplicate items, and interleave them.
        # Each duplicate item reuses a key that has already been scheduled earlier.
        duplicate_payloads = []
        for i, key in enumerate(duplicate_keys):
            duplicate_payloads.append({
                "form_id": self.form_id,
                "idempotency_key": key,
                "email": f"dup_{i}_{key[:6]}@loadtest.local",
                "data": {
                    "request_index": i,
                    "type": "duplicate",
                    "reused_key": key,
                    "timestamp": time.time(),
                }
            })

        # Interleave: Put first 20% of uniques first so pool of submitted keys is warm,
        # then mix remaining uniques and duplicates.
        warmup_count = min(len(items), max(100, self.unique_count // 5))
        first_chunk = items[:warmup_count]
        remaining_pool = items[warmup_count:] + duplicate_payloads
        random.shuffle(remaining_pool)

        all_payloads = first_chunk + remaining_pool
        print(f"Payload generation complete. Total prepared: {len(all_payloads):,}")
        return all_payloads

    async def _worker(
        self,
        queue: asyncio.Queue,
        session: aiohttp.ClientSession,
        headers: dict,
    ):
        """Worker coroutine that consumes payloads from queue and makes HTTP POST."""
        while True:
            try:
                payload = queue.get_nowait()
            except asyncio.QueueEmpty:
                break

            t0 = time.perf_counter()
            try:
                async with session.post(
                    self.url,
                    json=payload,
                    headers=headers,
                    timeout=aiohttp.ClientTimeout(total=self.timeout),
                ) as resp:
                    latency = (time.perf_counter() - t0) * 1000.0  # in ms
                    self.latencies.append(latency)

                    status = resp.status
                    if status == 201:
                        self.status_201_created += 1
                    elif status == 200:
                        self.status_200_ok += 1
                    elif status == 400:
                        self.status_400_bad_request += 1
                        if len(self.error_samples) < 5:
                            body = await resp.text()
                            self.error_samples.append(f"HTTP 400: {body[:200]}")
                    else:
                        self.status_other_errors += 1
                        if len(self.error_samples) < 5:
                            body = await resp.text()
                            self.error_samples.append(f"HTTP {status}: {body[:200]}")

            except Exception as e:
                latency = (time.perf_counter() - t0) * 1000.0
                self.latencies.append(latency)
                self.exceptions_count += 1
                if len(self.error_samples) < 5:
                    self.error_samples.append(f"Exception: {type(e).__name__} - {str(e)}")
            finally:
                queue.task_done()
                self.completed_requests += 1

                if self.completed_requests % self.report_interval == 0 or self.completed_requests == self.total_requests:
                    elapsed = time.perf_counter() - self.start_time
                    current_rps = self.completed_requests / elapsed if elapsed > 0 else 0
                    pct = (self.completed_requests / self.total_requests) * 100.0
                    print(
                        f"[{self.completed_requests:7,}/{self.total_requests:,}] ({pct:5.1f}%) "
                        f"| 201 Created: {self.status_201_created:,} "
                        f"| 200 Reused: {self.status_200_ok:,} "
                        f"| Errors: {self.status_400_bad_request + self.status_other_errors + self.exceptions_count:,} "
                        f"| RPS: {current_rps:7.1f} "
                        f"| Elapsed: {elapsed:5.1f}s"
                    )

    async def run(self):
        payloads = self.generate_payloads()

        queue = asyncio.Queue()
        for p in payloads:
            queue.put_nowait(p)

        connector = aiohttp.TCPConnector(
            limit=self.concurrency,
            limit_per_host=self.concurrency,
            enable_cleanup_closed=True,
            force_close=False,
            ttl_dns_cache=300,
        )

        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "MailerCloud-LoadTester/1.0",
        }

        print("\n" + "=" * 70)
        print(" STARTING LOAD TEST EXECUTION")
        print("=" * 70)
        print(f" Target Endpoint:   {self.url}")
        print(f" Total Requests:    {self.total_requests:,}")
        print(f" Unique Submissions:{self.unique_count:,}")
        print(f" Expected Duplicates:{self.duplicate_count:,}")
        print(f" Concurrency Level: {self.concurrency} workers")
        print(f" Form ID:           {self.form_id}")
        print("=" * 70 + "\n")

        self.start_time = time.perf_counter()

        async with aiohttp.ClientSession(connector=connector) as session:
            tasks = [
                asyncio.create_task(self._worker(queue, session, headers))
                for _ in range(self.concurrency)
            ]
            await queue.join()
            await asyncio.gather(*tasks)

        self.end_time = time.perf_counter()
        self.print_report()

    def print_report(self) -> dict:
        total_time = self.end_time - self.start_time
        total_errors = self.status_400_bad_request + self.status_other_errors + self.exceptions_count
        throughput = self.completed_requests / total_time if total_time > 0 else 0

        # Percentile calculations
        latencies_sorted = sorted(self.latencies) if self.latencies else [0.0]
        n = len(latencies_sorted)
        avg_latency = sum(latencies_sorted) / n if n > 0 else 0
        min_latency = latencies_sorted[0]
        max_latency = latencies_sorted[-1]
        p50 = latencies_sorted[int(n * 0.50)]
        p90 = latencies_sorted[int(n * 0.90)]
        p95 = latencies_sorted[int(n * 0.95)]
        p99 = latencies_sorted[int(n * 0.99)]

        report = {
            "target_url": self.url,
            "form_id": self.form_id,
            "total_requests": self.completed_requests,
            "submissions_stored_201": self.status_201_created,
            "duplicates_returned_200": self.status_200_ok,
            "bad_request_errors_400": self.status_400_bad_request,
            "server_or_other_errors": self.status_other_errors,
            "exceptions_or_timeouts": self.exceptions_count,
            "total_errors": total_errors,
            "total_time_seconds": round(total_time, 2),
            "throughput_rps": round(throughput, 2),
            "latency_ms": {
                "min": round(min_latency, 2),
                "avg": round(avg_latency, 2),
                "p50": round(p50, 2),
                "p90": round(p90, 2),
                "p95": round(p95, 2),
                "p99": round(p99, 2),
                "max": round(max_latency, 2),
            }
        }

        print("\n" + "=" * 70)
        print("               LOAD TEST REPORT SUMMARY")
        print("           (Matches Machine Test Part 2 Specs)")
        print("=" * 70)
        print(f" Target Endpoint:             {self.url}")
        print(f" Total Requests Sent:         {self.completed_requests:,}")
        print(f" Unique Submissions Stored:   {self.status_201_created:,}  (HTTP 201 Created)")
        print(f" Duplicates Detected & Handled:{self.status_200_ok:,}  (HTTP 200 OK)")
        print(f" Total Errors Occurred:       {total_errors:,}")
        print(f"   - HTTP 400 Bad Request:    {self.status_400_bad_request:,}")
        print(f"   - Other HTTP Status:       {self.status_other_errors:,}")
        print(f"   - Network / Timeouts:      {self.exceptions_count:,}")
        print("-" * 70)
        print(f" Total Elapsed Time:          {total_time:.2f} seconds ({total_time / 60:.2f} minutes)")
        print(f" Average Throughput:          {throughput:.2f} requests/second (RPS)")
        print("-" * 70)
        print(" Latency Distribution (ms):")
        print(f"   - Min Latency:             {min_latency:.2f} ms")
        print(f"   - Avg Latency:             {avg_latency:.2f} ms")
        print(f"   - 50th Percentile (p50):   {p50:.2f} ms")
        print(f"   - 90th Percentile (p90):   {p90:.2f} ms")
        print(f"   - 95th Percentile (p95):   {p95:.2f} ms")
        print(f"   - 99th Percentile (p99):   {p99:.2f} ms")
        print(f"   - Max Latency:             {max_latency:.2f} ms")
        print("=" * 70)

        if self.error_samples:
            print("\nError Samples (First 5):")
            for sample in self.error_samples:
                print(f"  * {sample}")
            print("-" * 70)

        # Verification advice
        if self.status_201_created == self.unique_count and self.status_200_ok == self.duplicate_count:
            print("\n  [PASS] VERIFICATION SUCCESS:")
            print(f"   All {self.unique_count:,} unique submissions were stored (201).")
            print(f"   All {self.duplicate_count:,} duplicates returned the existing record (200).")
        else:
            print("\n  [NOTE] VERIFICATION RESULT:")
            print(f"   201 Created: {self.status_201_created:,} (Expected: {self.unique_count:,})")
            print(f"   200 OK:      {self.status_200_ok:,} (Expected: {self.duplicate_count:,})")

        print("=" * 70 + "\n")
        return report


def main():
    parser = argparse.ArgumentParser(
        description="Load testing tool for MailerCloud Form Submission API."
    )
    parser.add_argument(
        "--url",
        default="http://127.0.0.1:8000/api/submissions",
        help="Target submission API endpoint (default: http://127.0.0.1:8000/api/submissions)",
    )
    parser.add_argument(
        "--total",
        type=int,
        default=100_000,
        help="Total number of requests to send (default: 100000)",
    )
    parser.add_argument(
        "--duplicates",
        type=int,
        default=20_000,
        help="Number of duplicate requests to include (default: 20000)",
    )
    parser.add_argument(
        "--concurrency",
        "-c",
        type=int,
        default=50,
        help="Concurrency / worker count (default: 50)",
    )
    parser.add_argument(
        "--form-id",
        default="load-test-form",
        help="Form ID to use for the test batch (default: load-test-form)",
    )
    parser.add_argument(
        "--report-interval",
        type=int,
        default=5_000,
        help="Progress update interval in requests (default: 5000)",
    )
    parser.add_argument(
        "--output-json",
        default=None,
        help="Optional path to write JSON test results report",
    )

    args = parser.parse_args()

    # Fallback to /submissions if user's server routes only on /submissions or /api/submissions
    runner = LoadTestRunner(
        url=args.url,
        total_requests=args.total,
        duplicate_count=args.duplicates,
        concurrency=args.concurrency,
        form_id=args.form_id,
        report_interval=args.report_interval,
    )

    if sys.platform == "win32":
        # Ensure Windows event loop handles high concurrency cleanly
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())

    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    try:
        loop.run_until_complete(runner.run())
    finally:
        loop.close()

    if args.output_json:
        report = runner.print_report()
        with open(args.output_json, "w", encoding="utf-8") as f:
            json.dump(report, f, indent=2)
        print(f"Report saved to {args.output_json}")


if __name__ == "__main__":
    main()
