#!/bin/sh
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname benchmark -v marker="$BENCH_MARKER" <<'SQL'
CREATE ROLE bench_runner LOGIN PASSWORD 'benchmark_runner_only' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE TABLE public.benchmark_marker (marker text PRIMARY KEY);
INSERT INTO public.benchmark_marker VALUES (:'marker');
GRANT CONNECT ON DATABASE benchmark TO bench_runner;
GRANT SELECT ON public.benchmark_marker TO bench_runner;
SQL
