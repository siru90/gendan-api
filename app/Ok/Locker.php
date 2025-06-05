<?php

namespace App\Ok;

class Locker
{

    public static function reentrantLock(string $key, $val, int $ex)
    {
        $val = (string)$val;
        if ($key === '' || $val === '' || $ex <= 0) return false;
        return \Illuminate\Support\Facades\Redis::eval(<<<'LUA'
if redis.call('exists', KEYS[1]) > 0 then
    if redis.call('get', KEYS[1]) == ARGV[1] then
        return redis.call('expire', KEYS[1], ARGV[2])
    else
        return false
    end
else
    return redis.call('set', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2])
end
LUA, 1, $key, $val, $ex);
    }

    public static function lock(string $key, int $ex)
    {
        return \Illuminate\Support\Facades\Redis::command("set", [$key, self::val1(), ['NX', 'EX' => $ex]]);
    }

    private static function val1(): string
    {
        return '1';
    }

    public static function unlock(string $key)
    {
        return self::unlockReentrant($key, self::val1());
    }

    public static function unlockReentrant(string $key, $val)
    {
        $val = (string)$val;
        if ($key === '' || $val === '') return false;
        return \Illuminate\Support\Facades\Redis::eval(<<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
else
    return 0
end
LUA, 1, $key, $val);
    }

    public static function te($lockKey, $val): object
    {
        return new class($lockKey, $val) {
            private string $lockKey;
            private string $val;

            public function __construct(string $lockKey, string $val)
            {
                $this->lockKey = $lockKey;
                $this->val = $val;
            }

            public function __destruct()
            {
                $lockKey = $this->lockKey;
                $val = $this->val;
                \App\Ok\Locker::unlockReentrant($lockKey, $val);
            }
        };
    }
}
