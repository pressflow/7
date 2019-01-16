<?php
namespace TYPO3\PharStreamWrapper;

/*
 * This file is part of the TYPO3 project.
 *
 * It is free software; you can redistribute it and/or modify it under the terms
 * of the MIT License (MIT). For the full copyright and license information,
 * please read the LICENSE file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

class PharStreamWrapper
{
    /**
     * Internal stream constants that are not exposed to PHP, but used...
     * @see https://github.com/php/php-src/blob/e17fc0d73c611ad0207cac8a4a01ded38251a7dc/main/php_streams.h
     */
    const STREAM_OPEN_FOR_INCLUDE = 128;

    /**
     * @var resource
     */
    public $context;

    /**
     * @var resource
     */
    protected $internalResource;

    /**
     * @return bool
     */
    public function dir_closedir()
    {
        if (!is_resource($this->internalResource)) {
            return false;
        }

        $this->invokeInternalStreamWrapper(
            'closedir',
            $this->internalResource
        );
        return !is_resource($this->internalResource);
    }

    /**
     * @param string $path
     * @param int $options
     * @return bool
     */
    public function dir_opendir($path, $options)
    {
        $this->assert($path, Behavior::COMMAND_DIR_OPENDIR);
        $this->internalResource = $this->invokeInternalStreamWrapper(
            'opendir',
            $path,
            $this->context
        );
        return is_resource($this->internalResource);
    }

    /**
     * @return string|false
     */
    public function dir_readdir()
    {
        return $this->invokeInternalStreamWrapper(
            'readdir',
            $this->internalResource
        );
    }

    /**
     * @return bool
     */
    public function dir_rewinddir()
    {
        if (!is_resource($this->internalResource)) {
            return false;
        }

        $this->invokeInternalStreamWrapper(
            'rewinddir',
            $this->internalResource
        );
        return is_resource($this->internalResource);
    }

    /**
     * @param string $path
     * @param int $mode
     * @param int $options
     * @return bool
     */
    public function mkdir($path, $mode, $options)
    {
        $this->assert($path, Behavior::COMMAND_MKDIR);
        return $this->invokeInternalStreamWrapper(
            'mkdir',
            $path,
            $mode,
            (bool) ($options & STREAM_MKDIR_RECURSIVE),
            $this->context
        );
    }

    /**
     * @param string $path_from
     * @param string $path_to
     * @return bool
     */
    public function rename($path_from, $path_to)
    {
        $this->assert($path_from, Behavior::COMMAND_RENAME);
        $this->assert($path_to, Behavior::COMMAND_RENAME);
        return $this->invokeInternalStreamWrapper(
            'rename',
            $path_from,
            $path_to,
            $this->context
        );
    }

    /**
     * @param string $path
     * @param int $options
     * @return bool
     */
    public function rmdir($path, $options)
    {
        $this->assert($path, Behavior::COMMAND_RMDIR);
        return $this->invokeInternalStreamWrapper(
            'rmdir',
            $path,
            $this->context
        );
    }

    /**
     * @param int $cast_as
     */
    public function stream_cast($cast_as)
    {
        throw new Exception(
            'Method stream_select() cannot be used',
            1530103999
        );
    }

    public function stream_close()
    {
        $this->invokeInternalStreamWrapper(
            'fclose',
            $this->internalResource
        );
    }

    /**
     * @return bool
     */
    public function stream_eof()
    {
        return $this->invokeInternalStreamWrapper(
            'feof',
            $this->internalResource
        );
    }

    /**
     * @return bool
     */
    public function stream_flush()
    {
        return $this->invokeInternalStreamWrapper(
            'fflush',
            $this->internalResource
        );
    }

    /**
     * @param int $operation
     * @return bool
     */
    public function stream_lock($operation)
    {
        return $this->invokeInternalStreamWrapper(
            'flock',
            $this->internalResource,
            $operation
        );
    }

    /**
     * @param string $path
     * @param int $option
     * @param string|int $value
     * @return bool
     */
    public function stream_metadata($path, $option, $value)
    {
        $this->assert($path, Behavior::COMMAND_STEAM_METADATA);
        if ($option === STREAM_META_TOUCH) {
            return call_user_func_array(
                array($this, 'invokeInternalStreamWrapper'),
                array_merge(array('touch', $path), (array) $value)
            );
        }
        if ($option === STREAM_META_OWNER_NAME || $option === STREAM_META_OWNER) {
            return $this->invokeInternalStreamWrapper(
                'chown',
                $path,
                $value
            );
        }
        if ($option === STREAM_META_GROUP_NAME || $option === STREAM_META_GROUP) {
            return $this->invokeInternalStreamWrapper(
                'chgrp',
                $path,
                $value
            );
        }
        if ($option === STREAM_META_ACCESS) {
            return $this->invokeInternalStreamWrapper(
                'chmod',
                $path,
                $value
            );
        }
        return false;
    }

    /**
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string|null $opened_path
     * @return bool
     */
    public function stream_open(
        $path,
        $mode,
        $options,
        &$opened_path = null
    ) {
        $this->assert($path, Behavior::COMMAND_STREAM_OPEN);
        $arguments = array($path, $mode, (bool) ($options & STREAM_USE_PATH));
        // only add stream context for non include/require calls
        if (!($options & static::STREAM_OPEN_FOR_INCLUDE)) {
            $arguments[] = $this->context;
        // work around https://bugs.php.net/bug.php?id=66569
        // for including files from Phar stream with OPcache enabled
        } else {
            Helper::resetOpCache();
        }
        $this->internalResource = call_user_func_array(
            array($this, 'invokeInternalStreamWrapper'),
            array_merge(array('fopen'), $arguments)
        );
        if (!is_resource($this->internalResource)) {
            return false;
        }
        if ($opened_path !== null) {
            $metaData = stream_get_meta_data($this->internalResource);
            $opened_path = $metaData['uri'];
        }
        return true;
    }

    /**
     * @param int $count
     * @return string
     */
    public function stream_read($count)
    {
        return $this->invokeInternalStreamWrapper(
            'fread',
            $this->internalResource,
            $count
        );
    }

    /**
     * @param int $offset
     * @param int $whence
     * @return bool
     */
    public function stream_seek($offset, $whence = SEEK_SET)
    {
        return $this->invokeInternalStreamWrapper(
            'fseek',
            $this->internalResource,
            $offset,
            $whence
        ) !== -1;
    }

    /**
     * @param int $option
     * @param int $arg1
     * @param int $arg2
     * @return bool
     */
    public function stream_set_option($option, $arg1, $arg2)
    {
        if ($option === STREAM_OPTION_BLOCKING) {
            return $this->invokeInternalStreamWrapper(
                'stream_set_blocking',
                $this->internalResource,
                $arg1
            );
        }
        if ($option === STREAM_OPTION_READ_TIMEOUT) {
            return $this->invokeInternalStreamWrapper(
                'stream_set_timeout',
                $this->internalResource,
                $arg1,
                $arg2
            );
        }
        if ($option === STREAM_OPTION_WRITE_BUFFER) {
            return $this->invokeInternalStreamWrapper(
                'stream_set_write_buffer',
                $this->internalResource,
                $arg2
            ) === 0;
        }
        return false;
    }

    /**
     * @return array
     */
    public function stream_stat()
    {
        return $this->invokeInternalStreamWrapper(
            'fstat',
            $this->internalResource
        );
    }

    /**
     * @return int
     */
    public function stream_tell()
    {
        return $this->invokeInternalStreamWrapper(
            'ftell',
            $this->internalResource
        );
    }

    /**
     * @param int $new_size
     * @return bool
     */
    public function stream_truncate($new_size)
    {
        return $this->invokeInternalStreamWrapper(
            'ftruncate',
            $this->internalResource,
            $new_size
        );
    }

    /**
     * @param string $data
     * @return int
     */
    public function stream_write($data)
    {
        return $this->invokeInternalStreamWrapper(
            'fwrite',
            $this->internalResource,
            $data
        );
    }

    /**
     * @param string $path
     * @return bool
     */
    public function unlink($path)
    {
        $this->assert($path, Behavior::COMMAND_UNLINK);
        return $this->invokeInternalStreamWrapper(
            'unlink',
            $path,
            $this->context
        );
    }

    /**
     * @param string $path
     * @param int $flags
     * @return array|false
     */
    public function url_stat($path, $flags)
    {
        $this->assert($path, Behavior::COMMAND_URL_STAT);
        $functionName = $flags & STREAM_URL_STAT_QUIET ? '@stat' : 'stat';
        return $this->invokeInternalStreamWrapper($functionName, $path);
    }

    /**
     * @param string $path
     * @param string $command
     */
    protected function assert($path, $command)
    {
        if ($this->resolveAssertable()->assert($path, $command) === true) {
            return;
        }

        throw new Exception(
            sprintf(
                'Denied invocation of "%s" for command "%s"',
                $path,
                $command
            ),
            1535189880
        );
    }

    /**
     * @return Assertable
     */
    protected function resolveAssertable()
    {
        return Manager::instance();
    }

    /**
     * Invokes commands on the native PHP Phar stream wrapper.
     *
     * @param string $functionName
     * @param mixed ...$arguments
     * @return mixed
     */
    private function invokeInternalStreamWrapper($functionName)
    {
        $arguments = func_get_args();
        array_shift($arguments);
        $silentExecution = $functionName{0} === '@';
        $functionName = ltrim($functionName, '@');
        $this->restoreInternalSteamWrapper();

        try {
            if ($silentExecution) {
                $result = @call_user_func_array($functionName, $arguments);
            } else {
                $result = call_user_func_array($functionName, $arguments);
            }
        } catch (\Exception $exception) {
            $this->registerStreamWrapper();
            throw $exception;
        } catch (\Throwable $throwable) {
            $this->registerStreamWrapper();
            throw $throwable;
        }

        $this->registerStreamWrapper();
        return $result;
    }

    private function restoreInternalSteamWrapper()
    {
        stream_wrapper_restore('phar');
    }

    private function registerStreamWrapper()
    {
        stream_wrapper_unregister('phar');
        stream_wrapper_register('phar', get_class($this));
    }
}
