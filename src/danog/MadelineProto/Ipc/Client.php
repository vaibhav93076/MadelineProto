<?php

declare(strict_types=1);

/**
 * API wrapper module.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Ipc;

use Amp\Ipc\Sync\ChannelledSocket;
use danog\MadelineProto\Exception;
use danog\MadelineProto\FileCallbackInterface;
use danog\MadelineProto\Logger;
use danog\MadelineProto\MTProtoTools\FilesLogic;
use danog\MadelineProto\SessionPaths;
use danog\MadelineProto\Tools;
use danog\MadelineProto\Wrappers\Start;
use danog\MadelineProto\Wrappers\Templates;
use Generator;
use Throwable;

use function Amp\async;

/**
 * IPC client.
 */
class Client extends ClientAbstract
{
    use Start;
    use Templates;
    use FilesLogic;

    /**
     * Instances.
     */
    private static array $instances = [];

    /**
     * Returns an instance of a client by session name.
     */
    public static function giveInstanceBySession(string $session): Client
    {
        return self::$instances[$session];
    }

    /**
     * Whether the wrapper API is async.
     */
    public bool $async;

    /**
     * Session.
     */
    protected SessionPaths $session;
    /**
     * Constructor function.
     *
     * @param SessionPaths     $session Session paths
     * @param Logger           $logger  Logger
     * @param bool             $async   Whether the wrapper API is async
     */
    public function __construct(ChannelledSocket $server, SessionPaths $session, Logger $logger, bool &$async)
    {
        $this->async = &$async;
        $this->logger = $logger;
        $this->server = $server;
        $this->session = $session;
        self::$instances[$session->getLegacySessionPath()] = $this;
        async($this->loopInternal(...));
    }
    /**
     * Run the provided async callable.
     *
     * @param callable $callback Async callable to run
     */
    public function loop(callable $callback)
    {
        return $callback();
    }
    /**
     * Unreference.
     */
    public function unreference(): void
    {
        try {
            Tools::wait($this->disconnect());
        } catch (Throwable $e) {
            $this->logger("An error occurred while disconnecting the client: $e");
        }
        if (isset(self::$instances[$this->session->getLegacySessionPath()])) {
            unset(self::$instances[$this->session->getLegacySessionPath()]);
        }
    }
    /**
     * Stop IPC server instance.
     *
     * @internal
     */
    public function stopIpcServer(): void
    {
        $this->run = false;
        $this->server->send(Server::SHUTDOWN);
    }
    /**
     * Restart IPC server instance.
     *
     * @internal
     */
    public function restartIpcServer(): void
    {
        $this->server->send(Server::SHUTDOWN);
    }
    /**
     * Whether we're an IPC client instance.
     */
    public function isIpc(): bool
    {
        return true;
    }

    /**
     * Upload file from URL.
     *
     * @param string|FileCallbackInterface $url       URL of file
     * @param integer                      $size      Size of file
     * @param string                       $fileName  File name
     * @param callable                     $cb        Callback (DEPRECATED, use FileCallbackInterface)
     * @param boolean                      $encrypted Whether to encrypt file for secret chats
     */
    public function uploadFromUrl($url, int $size = 0, string $fileName = '', callable $cb = null, bool $encrypted = false)
    {
        if (\is_object($url) && $url instanceof FileCallbackInterface) {
            $cb = $url;
            $url = $url->getFile();
        }
        $params = [$url, $size, $fileName, &$cb, $encrypted];
        $wrapper = Wrapper::create($params, $this->session, $this->logger);
        $wrapper->wrap($cb, false);
        return $this->__call('uploadFromUrl', $wrapper);
    }
    /**
     * Upload file from callable.
     *
     * The callable must accept two parameters: int $offset, int $size
     * The callable must return a string with the contest of the file at the specified offset and size.
     *
     * @param mixed    $callable  Callable
     * @param integer  $size      File size
     * @param string   $mime      Mime type
     * @param string   $fileName  File name
     * @param callable $cb        Callback (DEPRECATED, use FileCallbackInterface)
     * @param boolean  $seekable  Whether chunks can be fetched out of order
     * @param boolean  $encrypted Whether to encrypt file for secret chats
     */
    public function uploadFromCallable(callable $callable, int $size, string $mime, string $fileName = '', callable $cb = null, bool $seekable = true, bool $encrypted = false)
    {
        if (\is_object($callable) && $callable instanceof FileCallbackInterface) {
            $cb = $callable;
            $callable = $callable->getFile();
        }
        $params = [&$callable, $size, $mime, $fileName, &$cb, $seekable, $encrypted];
        $wrapper = Wrapper::create($params, $this->session, $this->logger);
        $wrapper->wrap($cb, false);
        $wrapper->wrap($callable, false);
        return $this->__call('uploadFromCallable', $wrapper);
    }
    /**
     * Reupload telegram file.
     *
     * @param mixed    $media     Telegram file
     * @param callable $cb        Callback (DEPRECATED, use FileCallbackInterface)
     * @param boolean  $encrypted Whether to encrypt file for secret chats
     */
    public function uploadFromTgfile($media, callable $cb = null, bool $encrypted = false)
    {
        if (\is_object($media) && $media instanceof FileCallbackInterface) {
            $cb = $media;
            $media = $media->getFile();
        }
        $params = [$media, &$cb, $encrypted];
        $wrapper = Wrapper::create($params, $this->session, $this->logger);
        $wrapper->wrap($cb, false);
        return $this->__call('uploadFromTgfile', $wrapper);
    }
    /**
     * Call method and wait asynchronously for response.
     *
     * If the $aargs['noResponse'] is true, will not wait for a response.
     *
     * @param string            $method Method name
     * @param array|Generator $args Arguments
     * @param array             $aargs  Additional arguments
     * @psalm-param array|Generator<mixed, mixed, mixed, array> $args
     */
    public function methodCallAsyncRead(string $method, $args, array $aargs)
    {
        if (\is_array($args)) {
            if (($method === 'messages.editInlineBotMessage' ||
                $method === 'messages.uploadMedia' ||
                $method === 'messages.sendMedia' ||
                $method === 'messages.editMessage') &&
                isset($args['media']['file']) &&
                $args['media']['file'] instanceof FileCallbackInterface
            ) {
                $params = [$method, &$args, $aargs];
                $wrapper = Wrapper::create($params, $this->session, $this->logger);
                $wrapper->wrap($args['media']['file'], true);
                return $this->__call('methodCallAsyncRead', $wrapper);
            } elseif ($method === 'messages.sendMultiMedia' && isset($args['multi_media'])) {
                $params = [$method, &$args, $aargs];
                $wrapper = Wrapper::create($params, $this->session, $this->logger);
                foreach ($args['multi_media'] as &$media) {
                    if (isset($media['media']['file']) && $media['media']['file'] instanceof FileCallbackInterface) {
                        $wrapper->wrap($media['media']['file'], true);
                    }
                }
                return $this->__call('methodCallAsyncRead', $wrapper);
            }
        }
        return $this->__call('methodCallAsyncRead', [$method, $args, $aargs]);
    }
    /**
     * Download file to directory.
     *
     * @param mixed                        $messageMedia File to download
     * @param string|FileCallbackInterface $dir           Directory where to download the file
     * @param callable                     $cb            Callback (DEPRECATED, use FileCallbackInterface)
     */
    public function downloadToDir($messageMedia, $dir, callable $cb = null)
    {
        if (\is_object($dir) && $dir instanceof FileCallbackInterface) {
            $cb = $dir;
            $dir = $dir->getFile();
        }
        $params = [$messageMedia, $dir, &$cb];
        $wrapper = Wrapper::create($params, $this->session, $this->logger);
        $wrapper->wrap($cb, false);
        return $this->__call('downloadToDir', $wrapper);
    }
    /**
     * Download file.
     *
     * @param mixed                        $messageMedia File to download
     * @param string|FileCallbackInterface $file          Downloaded file path
     * @param callable                     $cb            Callback (DEPRECATED, use FileCallbackInterface)
     */
    public function downloadToFile($messageMedia, $file, callable $cb = null)
    {
        if (\is_object($file) && $file instanceof FileCallbackInterface) {
            $cb = $file;
            $file = $file->getFile();
        }
        $params = [$messageMedia, $file, &$cb];
        $wrapper = Wrapper::create($params, $this->session, $this->logger);
        $wrapper->wrap($cb, false);
        return $this->__call('downloadToFile', $wrapper);
    }
    /**
     * Download file to callable.
     * The callable must accept two parameters: string $payload, int $offset
     * The callable will be called (possibly out of order, depending on the value of $seekable).
     * The callable should return the number of written bytes.
     *
     * @param mixed                          $messageMedia File to download
     * @param callable|FileCallbackInterface $callable     Chunk callback
     * @param callable                       $cb           Status callback (DEPRECATED, use FileCallbackInterface)
     * @param bool                           $seekable     Whether the callable can be called out of order
     * @param int                            $offset       Offset where to start downloading
     * @param int                            $end          Offset where to stop downloading (inclusive)
     * @param int                            $part_size    Size of each chunk
     */
    public function downloadToCallable($messageMedia, callable $callable, callable $cb = null, bool $seekable = true, int $offset = 0, int $end = -1, int $part_size = null)
    {
        $messageMedia = ($this->getDownloadInfo($messageMedia));
        if (\is_object($callable) && $callable instanceof FileCallbackInterface) {
            $cb = $callable;
            $callable = $callable->getFile();
        }
        $params = [$messageMedia, &$callable, &$cb, $seekable, $offset, $end, $part_size, ];
        $wrapper = Wrapper::create($params, $this->session, $this->logger);
        $wrapper->wrap($callable, false);
        $wrapper->wrap($cb, false);
        return $this->__call('downloadToCallable', $wrapper);
    }
    /**
     * Placeholder.
     *
     * @param mixed ...$params Params
     */
    public function setEventHandler(...$params): void
    {
        throw new Exception("Can't use ".__FUNCTION__." in an IPC client instance, please use startAndLoop, instead!");
    }
    /**
     * Placeholder.
     *
     * @param mixed ...$params Params
     */
    public function getEventHandler(...$params): void
    {
        throw new Exception("Can't use ".__FUNCTION__." in an IPC client instance, please use startAndLoop, instead!");
    }
}
