<?php
/**
 * Class Cti_Cache_Cache
 *
 * @category Cti
 * @package  Cti_Cache
 * @author   Paul Partington <p.partington@ctidigital.com>
 */
class Cti_Cache_Cache
{
    // Whether Redis can be used
    private $_useRedis;
    // Redis instance to make requests
    private $_redis;
    // Redis database to store the flag
    private $_redisDatabase;
    // Maximum amount of attempts to reload the request
    private $_maxAttempts = 10;
    // The time between retries in microseconds (defaults to 10 seconds)
    private $_retryTime = 10000000;
    // The key to store in the redis database
    private $_cacheKey = 'cache_lock';

    public function __construct ()
    {
        // Get the local XML file
        $localXml = realpath(dirname(__FILE__)).DS.'..'.DS.'..'.DS.'..'.DS.'app'.DS.'etc'.DS.'local.xml';

        try {
            if (!file_exists($localXml)) {
                throw new Exception('local.xml could not be found');
            }

            $xml = simplexml_load_file($localXml);

            // Redis connection details
            $server = (string) $xml->global->cache_lock->server;
            $port = (string) $xml->global->cache_lock->port;
            $this->_redisDatabase = (int) $xml->global->cache_lock->database;
            $password = (string) $xml->global->cache_lock->password;
            $timeout = null;
            $persistent = '';

            if (isset($xml->global->cache_lock->timeout)) {
                $timeout = (float) $xml->global->cache_lock->timeout;
            }

            if (isset($xml->global->cache_lock->persistent)) {
                $persistent = (string) $xml->global->cache_lock->persistent;
            }

            if (isset($xml->global->cache_lock->max_attempts)) {
                $this->_maxAttempts = (int) $xml->global->cache_lock->max_attempts;
            }

            if (isset($xml->global->cache_lock->retry_time)) {
                $this->_retryTime = (int) $xml->global->cache_lock->retry_time;
            }

            if (isset($xml->global->cache_lock->cache_key)) {
                $this->_cacheKey = (string) $xml->global->cache_lock->cache_key;
            }

            // Create a new Redis client
            $this->_redis = new Credis_Client($server, $port, $timeout, $persistent);

            if (!empty($password)) {
                $this->_redis->auth($password);
            }

            $this->_redis->setCloseOnDestruct(FALSE);

            $this->_useRedis = true;
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Gets a redis key and value from the database
     *
     * @param null $key
     * @return bool
     */
    private function _getRedis ($key = null)
    {
        try {
            if ($this->_useRedis === false) {
                throw new Exception('Cannot connect to Redis.');
            }
            if (is_null($key)) {
                throw new Exception('No key was specified');
            }
            $pipeline = $this->_redis->pipeline()
                ->select($this->_redisDatabase)
                ->get($key)
                ->exec();

            if (is_array($pipeline)) {
                return $pipeline;
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }

        return false;
    }

    /**
     * Sets the value of a key in Redis
     *
     * @param null $key
     * @param null $value
     *
     * @return bool
     */
    private function _setRedis ($key = null, $value = null)
    {
        try {
            if ($this->_useRedis === false) {
                throw new Exception('Cannot connect to Redis.');
            }
            if (is_null($key)) {
                throw new Exception('No key was specified.');
            }
            if (is_null($value)) {
                throw new Exception('No value was specified.');
            }

            $this->_redis->pipeline()
                ->select($this->_redisDatabase)
                ->set($key, $value)
                ->exec();

            return true;
        } catch (Exception $e) {
            Mage::logException($e);
        }

        return false;
    }

    /**
     * Enables the cache lock in Redis
     *
     * @return bool
     */
    public function acquireCacheLock ()
    {
        $lock = $this->_setRedis($this->_cacheKey, '1');
        if ($lock === true) {
            return true;
        }
        return false;
    }

    /**
     * Remvoes the cache lock in Redis
     *
     * @return bool
     */
    public function releaseCacheLock ()
    {
        $lock = $this->_setRedis($this->_cacheKey, '0');
        if ($lock === true) {
            return true;
        }
        return false;
    }

    /**
     * Returns if the cache is locked in Redis
     *
     * @return bool
     */
    public function getIsCacheLocked ()
    {
        $locked = $this->_getRedis($this->_cacheKey);

        if (is_array($locked) && isset($locked[0]) && isset($locked[1])) {
            if ((bool) $locked[0] === true) {
                return (bool) $locked[1];
            }
        }

        return false;
    }

    /**
     * Gets the maximum amount of attempts the request should be retried
     *
     * @return int
     */
    public function getMaxAttempts ()
    {
        return $this->_maxAttempts;
    }

    /**
     * Gets the time to wait between retring the request
     *
     * @return int
     */
    public function getRetryTime ()
    {
        return $this->_retryTime;
    }
}