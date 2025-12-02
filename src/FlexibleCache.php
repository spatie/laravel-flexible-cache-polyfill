<?php

namespace Spatie\FlexibleCache;

use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Polyfill for Cache::flexible() available in Laravel 11+.
 *
 * Implements the "stale-while-revalidate" caching pattern for Laravel 10.
 * Returns cached data immediately while refreshing stale data in the background
 * after the response is sent.
 */
class FlexibleCache
{
    /** @var array<string, bool> */
    protected array $scheduled = [];

    protected ?string $store = null;

    protected ?CacheRepository $repository = null;

    public function store(?string $store): self
    {
        $this->store = $store;

        return $this;
    }

    public function usingRepository(CacheRepository $repository): self
    {
        $this->repository = $repository;

        return $this;
    }

    /**
     * @template TCacheValue
     *
     * @param  string  $key  The cache key
     * @param  array{0: DateTimeInterface|DateInterval|int, 1: DateTimeInterface|DateInterval|int}  $ttl
     *                                                                                                    First value: seconds the cache is considered "fresh"
     *                                                                                                    Second value: seconds the cache can be served as "stale" before hard expiration
     * @param  callable(): TCacheValue  $callback  The callback to generate the cached value
     * @param  array{seconds?: int, owner?: string}|null  $lock  Optional lock configuration
     * @return TCacheValue
     */
    public function flexible(string $key, array $ttl, callable $callback, ?array $lock = null): mixed
    {
        $cache = $this->cache();
        $createdKey = "illuminate:cache:flexible:created:{$key}";

        $this->store = null;
        $this->repository = null;

        $cached = $cache->many([$key, $createdKey]);
        $value = $cached[$key];
        $created = $cached[$createdKey];

        // If cache is missing, immediately resolve, cache and return.
        if ($value === null || $created === null) {
            $value = $callback();
            $seconds = $this->getSeconds($ttl[1]);

            $cache->putMany([
                $key => $value,
                $createdKey => Carbon::now()->getTimestamp(),
            ], $seconds);

            return $value;
        }

        $freshSeconds = $this->getSeconds($ttl[0]);

        // If we're still in the fresh period, return value
        if (($created + $freshSeconds) > Carbon::now()->getTimestamp()) {
            return $value;
        }

        // Value is stale. Return it but schedule refresh in terminating callback only once (see $this->scheduled)
        // Use object ID to differentiate between cache stores
        $scheduledKey = 'illuminate:cache:flexible:'.spl_object_id($cache).':'.$key;

        if (! isset($this->scheduled[$scheduledKey])) {
            $this->scheduled[$scheduledKey] = true;

            app()->terminating(function () use ($cache, $key, $ttl, $callback, $lock, $created, $createdKey) {
                $lockKey = "illuminate:cache:flexible:lock:{$key}";
                $lockSeconds = $lock['seconds'] ?? 0;
                $lockOwner = $lock['owner'] ?? null;

                $cacheLock = $cache->lock($lockKey, $lockSeconds, $lockOwner);

                $cacheLock->get(function () use ($cache, $key, $callback, $created, $createdKey, $ttl) {
                    // Double-check that another process hasn't already refreshed the value
                    if ($created !== $cache->get($createdKey)) {
                        return;
                    }

                    $seconds = $this->getSeconds($ttl[1]);

                    $cache->putMany([
                        $key => $callback(),
                        $createdKey => Carbon::now()->getTimestamp(),
                    ], $seconds);
                });
            });
        }

        return $value;
    }

    protected function cache(): Repository
    {
        if ($this->repository) {
            return $this->repository;
        }

        /** @var CacheManager $manager */
        $manager = Cache::getFacadeRoot();

        return $manager->store($this->store);
    }

    protected function getSeconds(DateTimeInterface|DateInterval|int $ttl): int
    {
        if ($ttl instanceof DateInterval) {
            $ttl = Carbon::now()->add($ttl);
        }

        if ($ttl instanceof DateTimeInterface) {
            $ttl = (int) ceil(Carbon::now()->diffInMilliseconds($ttl, false) / 1000);
        }

        return (int) max(0, $ttl);
    }
}
