<?php

namespace Laminas\Cache\Storage\Adapter;

use APCuIterator as BaseApcuIterator;
use Laminas\Cache\Exception;
use Laminas\Cache\Storage\AvailableSpaceCapableInterface;
use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\ClearByNamespaceInterface;
use Laminas\Cache\Storage\ClearByPrefixInterface;
use Laminas\Cache\Storage\FlushableInterface;
use Laminas\Cache\Storage\IterableInterface;
use Laminas\Cache\Storage\TotalSpaceCapableInterface;
use stdClass;
use Traversable;

class Apcu extends AbstractAdapter implements
    AvailableSpaceCapableInterface,
    ClearByNamespaceInterface,
    ClearByPrefixInterface,
    FlushableInterface,
    IterableInterface,
    TotalSpaceCapableInterface
{
    /**
     * Buffered total space in bytes
     *
     * @var null|int|float
     */
    protected $totalSpace;

    /**
     * Constructor
     *
     * @param  null|array|Traversable|ApcuOptions $options
     * @throws Exception\ExceptionInterface
     */
    public function __construct($options = null)
    {
        if (version_compare(phpversion('apcu'), '5.1.0', '<')) {
            throw new Exception\ExtensionNotLoadedException('Missing ext/apcu >= 5.1.0');
        }

        if (! ini_get('apc.enabled') || (PHP_SAPI === 'cli' && ! ini_get('apc.enable_cli'))) {
            throw new Exception\ExtensionNotLoadedException(
                "ext/apcu is disabled - see 'apc.enabled' and 'apc.enable_cli'"
            );
        }

        parent::__construct($options);
    }

    /* options */

    /**
     * Set options.
     *
     * @param  array|Traversable|ApcuOptions $options
     * @return Apcu
     * @see    getOptions()
     */
    public function setOptions($options)
    {
        if (! $options instanceof ApcuOptions) {
            $options = new ApcuOptions($options);
        }

        return parent::setOptions($options);
    }

    /**
     * Get options.
     *
     * @return ApcuOptions
     * @see    setOptions()
     */
    public function getOptions()
    {
        if (! $this->options) {
            $this->setOptions(new ApcuOptions());
        }
        return $this->options;
    }

    /* TotalSpaceCapableInterface */

    /**
     * Get total space in bytes
     *
     * @return int|float
     */
    public function getTotalSpace()
    {
        if ($this->totalSpace === null) {
            $smaInfo = apcu_sma_info(true);
            $this->totalSpace = $smaInfo['num_seg'] * $smaInfo['seg_size'];
        }

        return $this->totalSpace;
    }

    /* AvailableSpaceCapableInterface */

    /**
     * Get available space in bytes
     *
     * @return int|float
     */
    public function getAvailableSpace()
    {
        $smaInfo = apcu_sma_info(true);
        return $smaInfo['avail_mem'];
    }

    /* IterableInterface */

    /**
     * Get the storage iterator
     *
     * @return ApcuIterator
     */
    public function getIterator()
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = '';
        $pattern   = null;
        if ($namespace !== '') {
            $prefix  = $namespace . $options->getNamespaceSeparator();
            $pattern = '/^' . preg_quote($prefix, '/') . '/';
        }

        $baseIt = new BaseApcuIterator($pattern, 0, 1, APC_LIST_ACTIVE);
        return new ApcuIterator($this, $baseIt, $prefix);
    }

    /* FlushableInterface */

    /**
     * Flush the whole storage
     *
     * @return bool
     */
    public function flush()
    {
        return apcu_clear_cache();
    }

    /* ClearByNamespaceInterface */

    /**
     * Remove items by given namespace
     *
     * @param string $namespace
     * @return bool
     */
    public function clearByNamespace($namespace)
    {
        $namespace = (string) $namespace;
        if ($namespace === '') {
            throw new Exception\InvalidArgumentException('No namespace given');
        }

        $options = $this->getOptions();
        $prefix  = $namespace . $options->getNamespaceSeparator();
        $pattern = '/^' . preg_quote($prefix, '/') . '/';
        return apcu_delete(new BaseApcuIterator($pattern, 0, 1, APC_LIST_ACTIVE));
    }

    /* ClearByPrefixInterface */

    /**
     * Remove items matching given prefix
     *
     * @param string $prefix
     * @return bool
     */
    public function clearByPrefix($prefix)
    {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            throw new Exception\InvalidArgumentException('No prefix given');
        }

        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $nsPrefix  = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $pattern = '/^' . preg_quote($nsPrefix . $prefix, '/') . '/';
        return apcu_delete(new BaseApcuIterator($pattern, 0, 1, APC_LIST_ACTIVE));
    }

    /* reading */

    /**
     * Internal method to get an item.
     *
     * @param  string  $normalizedKey
     * @param  bool    $success
     * @param  mixed   $casToken
     * @return mixed Data on success, null on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetItem(& $normalizedKey, & $success = null, & $casToken = null)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;
        $result      = apcu_fetch($internalKey, $success);

        if (! $success) {
            return;
        }

        $casToken = $result;
        return $result;
    }

    /**
     * Internal method to get multiple items.
     *
     * @param  array $normalizedKeys
     * @return array Associative array of keys and values
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetItems(array & $normalizedKeys)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        if ($namespace === '') {
            return apcu_fetch($normalizedKeys);
        }

        $prefix       = $namespace . $options->getNamespaceSeparator();
        $internalKeys = [];
        foreach ($normalizedKeys as $normalizedKey) {
            $internalKeys[] = $prefix . $normalizedKey;
        }

        $fetch = apcu_fetch($internalKeys);

        // remove namespace prefix
        $prefixL = strlen($prefix);
        $result  = [];
        foreach ($fetch as $internalKey => $value) {
            $result[substr($internalKey, $prefixL)] = $value;
        }

        return $result;
    }

    /**
     * Internal method to test if an item exists.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalHasItem(& $normalizedKey)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        return apcu_exists($prefix . $normalizedKey);
    }

    /**
     * Internal method to test multiple items.
     *
     * @param  array $normalizedKeys
     * @return array Array of found keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalHasItems(array & $normalizedKeys)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        if ($namespace === '') {
            // array_filter with no callback will remove entries equal to FALSE
            return array_keys(array_filter(apcu_exists($normalizedKeys)));
        }

        $prefix       = $namespace . $options->getNamespaceSeparator();
        $internalKeys = [];
        foreach ($normalizedKeys as $normalizedKey) {
            $internalKeys[] = $prefix . $normalizedKey;
        }

        $exists  = apcu_exists($internalKeys);
        $result  = [];
        $prefixL = strlen($prefix);
        foreach ($exists as $internalKey => $bool) {
            if ($bool === true) {
                $result[] = substr($internalKey, $prefixL);
            }
        }

        return $result;
    }

    /**
     * Get metadata of an item.
     *
     * @param  string $normalizedKey
     * @return array|bool Metadata on success, false on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalGetMetadata(& $normalizedKey)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;

        $format   = APC_ITER_ALL ^ APC_ITER_VALUE ^ APC_ITER_TYPE ^ APC_ITER_REFCOUNT;
        $regexp   = '/^' . preg_quote($internalKey, '/') . '$/';
        $it       = new BaseApcuIterator($regexp, $format, 100, APC_LIST_ACTIVE);
        $metadata = $it->current();

        if (! $metadata) {
            return false;
        }

        $this->normalizeMetadata($metadata);
        return $metadata;
    }

    /**
     * Get metadata of multiple items
     *
     * @param  array $normalizedKeys
     * @return array Associative array of keys and metadata
     *
     * @triggers getMetadatas.pre(PreEvent)
     * @triggers getMetadatas.post(PostEvent)
     * @triggers getMetadatas.exception(ExceptionEvent)
     */
    protected function internalGetMetadatas(array & $normalizedKeys)
    {
        $keysRegExp = [];
        foreach ($normalizedKeys as $normalizedKey) {
            $keysRegExp[] = preg_quote($normalizedKey, '/');
        }

        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefixL   = 0;

        if ($namespace === '') {
            $pattern = '/^(' . implode('|', $keysRegExp) . ')' . '$/';
        } else {
            $prefix  = $namespace . $options->getNamespaceSeparator();
            $prefixL = strlen($prefix);
            $pattern = '/^' . preg_quote($prefix, '/') . '(' . implode('|', $keysRegExp) . ')' . '$/';
        }

        $format  = APC_ITER_ALL ^ APC_ITER_VALUE ^ APC_ITER_TYPE ^ APC_ITER_REFCOUNT;
        $it      = new BaseApcuIterator($pattern, $format, 100, APC_LIST_ACTIVE);
        $result  = [];
        foreach ($it as $internalKey => $metadata) {
            $this->normalizeMetadata($metadata);
            $result[substr($internalKey, $prefixL)] = $metadata;
        }

        return $result;
    }

    /* writing */

    /**
     * Internal method to store an item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItem(& $normalizedKey, & $value)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;
        $ttl         = $options->getTtl();

        if (! apcu_store($internalKey, $value, $ttl)) {
            $type = is_object($value) ? get_class($value) : gettype($value);
            throw new Exception\RuntimeException(
                "apcu_store('{$internalKey}', <{$type}>, {$ttl}) failed"
            );
        }

        return true;
    }

    /**
     * Internal method to store multiple items.
     *
     * @param  array $normalizedKeyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalSetItems(array & $normalizedKeyValuePairs)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        if ($namespace === '') {
            return array_keys(apcu_store($normalizedKeyValuePairs, null, $options->getTtl()));
        }

        $prefix                = $namespace . $options->getNamespaceSeparator();
        $internalKeyValuePairs = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            $internalKey = $prefix . $normalizedKey;
            $internalKeyValuePairs[$internalKey] = $value;
        }

        $failedKeys = apcu_store($internalKeyValuePairs, null, $options->getTtl());
        $failedKeys = array_keys($failedKeys);

        // remove prefix
        $prefixL = strlen($prefix);
        foreach ($failedKeys as $key) {
            $key = substr($key, $prefixL);
        }

        return $failedKeys;
    }

    /**
     * Add an item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItem(& $normalizedKey, & $value)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;
        $ttl         = $options->getTtl();

        if (! apcu_add($internalKey, $value, $ttl)) {
            if (apcu_exists($internalKey)) {
                return false;
            }

            $type = is_object($value) ? get_class($value) : gettype($value);
            throw new Exception\RuntimeException(
                "apcu_add('{$internalKey}', <{$type}>, {$ttl}) failed"
            );
        }

        return true;
    }

    /**
     * Internal method to add multiple items.
     *
     * @param  array $normalizedKeyValuePairs
     * @return array Array of not stored keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalAddItems(array & $normalizedKeyValuePairs)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        if ($namespace === '') {
            return array_keys(apcu_add($normalizedKeyValuePairs, null, $options->getTtl()));
        }

        $prefix                = $namespace . $options->getNamespaceSeparator();
        $internalKeyValuePairs = [];
        foreach ($normalizedKeyValuePairs as $normalizedKey => $value) {
            $internalKey = $prefix . $normalizedKey;
            $internalKeyValuePairs[$internalKey] = $value;
        }

        $failedKeys = apcu_add($internalKeyValuePairs, null, $options->getTtl());
        $failedKeys = array_keys($failedKeys);

        // remove prefix
        $prefixL = strlen($prefix);
        foreach ($failedKeys as & $key) {
            $key = substr($key, $prefixL);
        }

        return $failedKeys;
    }

    /**
     * Internal method to replace an existing item.
     *
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalReplaceItem(& $normalizedKey, & $value)
    {
        $options     = $this->getOptions();
        $ttl         = $options->getTtl();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;


        if (! apcu_exists($internalKey)) {
            return false;
        }

        if (! apcu_store($internalKey, $value, $ttl)) {
            $type = is_object($value) ? get_class($value) : gettype($value);
            throw new Exception\RuntimeException(
                "apcu_store('{$internalKey}', <{$type}>, {$ttl}) failed"
            );
        }

        return true;
    }

    /**
     * Internal method to set an item only if token matches
     *
     * @param  mixed  $token
     * @param  string $normalizedKey
     * @param  mixed  $value
     * @return bool
     * @see    getItem()
     * @see    setItem()
     */
    protected function internalCheckAndSetItem(& $token, & $normalizedKey, & $value)
    {
        if (is_int($token) && is_int($value)) {
            return apcu_cas($normalizedKey, $token, $value);
        }

        return parent::internalCheckAndSetItem($token, $normalizedKey, $value);
    }

    /**
     * Internal method to remove an item.
     *
     * @param  string $normalizedKey
     * @return bool
     * @throws Exception\ExceptionInterface
     */
    protected function internalRemoveItem(& $normalizedKey)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        $prefix    = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        return apcu_delete($prefix . $normalizedKey);
    }

    /**
     * Internal method to remove multiple items.
     *
     * @param  array $normalizedKeys
     * @return array Array of not removed keys
     * @throws Exception\ExceptionInterface
     */
    protected function internalRemoveItems(array & $normalizedKeys)
    {
        $options   = $this->getOptions();
        $namespace = $options->getNamespace();
        if ($namespace === '') {
            return apcu_delete($normalizedKeys);
        }

        $prefix       = $namespace . $options->getNamespaceSeparator();
        $internalKeys = [];
        foreach ($normalizedKeys as $normalizedKey) {
            $internalKeys[] = $prefix . $normalizedKey;
        }

        $failedKeys = apcu_delete($internalKeys);

        // remove prefix
        $prefixL = strlen($prefix);
        foreach ($failedKeys as & $key) {
            $key = substr($key, $prefixL);
        }

        return $failedKeys;
    }

    /**
     * Internal method to increment an item.
     *
     * @param  string $normalizedKey
     * @param  int    $value
     * @return int|bool The new value on success, false on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalIncrementItem(& $normalizedKey, & $value)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;
        $value       = (int) $value;
        $newValue    = apcu_inc($internalKey, $value);

        // initial value
        if ($newValue === false) {
            $ttl      = $options->getTtl();
            $newValue = $value;
            if (! apcu_add($internalKey, $newValue, $ttl)) {
                throw new Exception\RuntimeException(
                    "apcu_add('{$internalKey}', {$newValue}, {$ttl}) failed"
                );
            }
        }

        return $newValue;
    }

    /**
     * Internal method to decrement an item.
     *
     * @param  string $normalizedKey
     * @param  int    $value
     * @return int|bool The new value on success, false on failure
     * @throws Exception\ExceptionInterface
     */
    protected function internalDecrementItem(& $normalizedKey, & $value)
    {
        $options     = $this->getOptions();
        $namespace   = $options->getNamespace();
        $prefix      = ($namespace === '') ? '' : $namespace . $options->getNamespaceSeparator();
        $internalKey = $prefix . $normalizedKey;
        $value       = (int) $value;
        $newValue    = apcu_dec($internalKey, $value);

        // initial value
        if ($newValue === false) {
            $ttl      = $options->getTtl();
            $newValue = -$value;
            if (! apcu_add($internalKey, $newValue, $ttl)) {
                throw new Exception\RuntimeException(
                    "apcu_add('{$internalKey}', {$newValue}, {$ttl}) failed"
                );
            }
        }

        return $newValue;
    }

    /* status */

    /**
     * Internal method to get capabilities of this adapter
     *
     * @return Capabilities
     */
    protected function internalGetCapabilities()
    {
        if ($this->capabilities === null) {
            $marker       = new stdClass();
            $capabilities = new Capabilities(
                $this,
                $marker,
                [
                    'supportedDatatypes' => [
                        'NULL'     => true,
                        'boolean'  => true,
                        'integer'  => true,
                        'double'   => true,
                        'string'   => true,
                        'array'    => true,
                        'object'   => 'object',
                        'resource' => false,
                    ],
                    'supportedMetadata' => [
                        'internal_key',
                        'atime', 'ctime', 'mtime', 'rtime',
                        'size', 'hits', 'ttl',
                    ],
                    'minTtl'             => 1,
                    'maxTtl'             => 0,
                    'staticTtl'          => true,
                    'ttlPrecision'       => 1,
                    'useRequestTime'     => (bool) ini_get('apc.use_request_time'),
                    'maxKeyLength'       => 5182,
                    'namespaceIsPrefix'  => true,
                    'namespaceSeparator' => $this->getOptions()->getNamespaceSeparator(),
                ]
            );

            // update namespace separator on change option
            $this->getEventManager()->attach('option', function ($event) use ($capabilities, $marker) {
                $params = $event->getParams();

                if (isset($params['namespace_separator'])) {
                    $capabilities->setNamespaceSeparator($marker, $params['namespace_separator']);
                }
            });

            $this->capabilities     = $capabilities;
            $this->capabilityMarker = $marker;
        }

        return $this->capabilities;
    }

    /* internal */

    /**
     * Normalize metadata to work with APC
     *
     * @param  array $metadata
     * @return void
     */
    protected function normalizeMetadata(array & $metadata)
    {
        $apcMetadata = $metadata;
        $metadata = [
            'internal_key' => $metadata['key'],
            'atime'        => $metadata['access_time'],
            'ctime'        => $metadata['creation_time'],
            'mtime'        => $metadata['mtime'],
            'rtime'        => $metadata['deletion_time'],
            'size'         => $metadata['mem_size'],
            'hits'         => $metadata['num_hits'],
            'ttl'          => $metadata['ttl'],
        ];
    }
}
