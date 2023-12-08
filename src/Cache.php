<?php

namespace BitrixPSR16;

use Exception;
use Psr\SimpleCache\CacheInterface;
use Bitrix\Main\Data\ICacheEngine;
use Bitrix\Main\Data\Cache as BitrixCache;

class Cache implements CacheInterface
{
    private ICacheEngine $cacheEngine;
    private int $defaultTtl;
    private string $baseDir;
    private string $initDir;
    /**
     * @var string[]
     */
    private array $allowedClassesForUnpacking = [];

    public function __construct(
        int $defaultTtl = 3600,
        ?ICacheEngine $cacheEngine = null,
        string $baseDir = "/bitrix/cache",
        string $initDir = ""
    ) {
        $this->defaultTtl = $defaultTtl;
        $this->cacheEngine = $cacheEngine ?? BitrixCache::createCacheEngine();
        $this->baseDir = $baseDir;
        $this->initDir = $initDir;
    }

    /**
     * @param string $className
     * @return void
     */
    public function addAllowedClass(string $className)
    {
        if (class_exists($className) || interface_exists($className)) {
            $this->allowedClassesForUnpacking[] = $className;
        }
    }

    private function isAllowPackingObject(object $objectForPacking): bool
    {
        if (empty($this->allowedClassesForUnpacking)) {
            return false;
        }

        foreach ($this->allowedClassesForUnpacking as $class) {
            if ($objectForPacking instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $data = '';
        $key = str_replace('//', '/', "/$key");
        $isSuccess = (bool)$this->cacheEngine->read(
            $data,
            $this->baseDir,
            $this->initDir,
            $key,
            $this->defaultTtl
        );

        if (!$isSuccess) {
            return $default;
        }

        $allowObjectUnpacking = !empty($this->allowedClassesForUnpacking);
        $unpackedData = $allowObjectUnpacking ?
            unserialize($data, ['allowed_classes' => $this->allowedClassesForUnpacking]) ?? json_decode($data, true) :
            json_decode($data, true);

        if ($unpackedData !== false && $unpackedData !== null) {
            $data = $unpackedData;
        }

        return $data ?: $default;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @return bool
     * @throws Exception
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function set($key, $value, $ttl = null): bool
    {
        $key = str_replace('//', '/', "/$key");
        if (is_object($value)) {
            if (!$this->isAllowPackingObject($value)) {
                throw new Exception('this is not allowed for packing');
            }
            $value = serialize($value);
        }

        if (is_array($value)) {
            $value = json_encode($value);
        }

        $this->cacheEngine->write($value, $this->baseDir, $this->initDir, $key, $ttl ?? $this->defaultTtl);
        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete($key): bool
    {
        $key = str_replace('//', '/', "/$key");
        $this->cacheEngine->clean($this->baseDir, $this->initDir, $key);
        return true;
    }

    public function clear(): bool
    {
        $this->cacheEngine->clean($this->baseDir, $this->initDir);
        return true;
    }

    /**
     * @param string[] $keys
     * @param mixed $default
     * @return array<string, mixed>
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function getMultiple($keys, $default = null): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $values
     * @param int $ttl
     * @return bool
     * @throws Exception
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            /**
             * @psalm-suppress MoreSpecificImplementedParamType,RedundantConditionGivenDocblockType
             */
            if (is_string($key) && !empty($key)) {
                $this->set($key, $value, $ttl);
            }
        }

        return true;
    }

    /**
     * @param string[] $keys
     * @return bool
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has($key): bool
    {
        $key = str_replace('//', '/', "/$key");
        $data = '';
        return (bool)$this->cacheEngine->read(
            $data,
            $this->baseDir,
            $this->initDir,
            $key,
            $this->defaultTtl
        );
    }
}
