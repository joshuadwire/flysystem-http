<?php

namespace Twistor\Flysystem\Http;

use BadMethodCallException;
use JetBrains\PhpStorm\Pure;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;

/**
 * Provides an adapter using PHP native HTTP functions.
 */
class HttpAdapter implements FilesystemAdapter
{
    /**
     * The base URL.
     *
     * @var string
     */
    protected string $base;

    /**
     * @var array
     */
    protected array $context;

    /**
     * @var bool
     */
    protected bool $supportsHead;

    /**
     * The visibility of this adapter.
     *
     * @var string
     */
    protected string $visibility = Visibility::PUBLIC;

    /**
     * Constructs an HttpAdapter object.
     *
     * @param string $base         The base URL
     * @param bool   $supportsHead Whether the endpoint supports HEAD requests
     * @param array  $context      Context options
     */
    public function __construct(string $base, bool $supportsHead = true, array $context = [])
    {
        $this->base = $base;
        $this->supportsHead = $supportsHead;
        $this->context = $context;

        // Add in some safe defaults for SSL/TLS. Don't know why PHPUnit/Xdebug
        // messes this up.
        // @codeCoverageIgnoreStart
        $this->context += [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'disable_compression' => true,
            ],
        ];
        // @codeCoverageIgnoreEnd

        if (isset(parse_url($base)['user'])) {
            $this->visibility = Visibility::PRIVATE;
        }
    }

    /**
     * @inheritdoc
     */
    public function copy(string $source, string $destination, Config $config):void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function move(string $source, string $destination, Config $config):void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function delete(string $path):void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function deleteDirectory(string $path): void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function directoryExists(string $path): bool
    {
        return (bool) $this->head(rtrim($path,'/\\') . '/');
    }


    /**
     * Returns the base path.
     *
     * @return string The base path
     */
    public function getBase(): string
    {
        return $this->base;
    }

    public function getMetadata($path): FileAttributes
    {
        if (false === $headers = $this->head($path)) {
            throw UnableToRetrieveMetadata::create($path, 'Could not fetch metadata');
        }

        return $this->parseMetadata($path, $headers);
    }

    /**
     * @inheritdoc
     */
    public function mimeType($path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @inheritdoc
     */
    public function fileSize($path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @inheritdoc
     */
    public function lastModified($path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    /**
     * @inheritdoc
     */
    #[Pure] public function visibility($path): FileAttributes
    {
        return new FileAttributes($path, null, $this->visibility);
    }

    /**
     * @inheritdoc
     */
    public function fileExists($path): bool
    {
        return (bool) $this->head($path);
    }

    /**
     * @inheritdoc
     */
    public function listContents(string $path = '', bool $deep = false):iterable
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function read(string $path):string
    {
        $context = stream_context_create($this->context);
        $contents = file_get_contents($this->buildUrl($path), false, $context);

        if ($contents === false) {
            return false;
        }

        return $contents;
    }

    /**
     * @inheritdoc
     */
    public function readStream($path)
    {
        $context = stream_context_create($this->context);
        $stream = fopen($this->buildUrl($path), 'rb', false, $context);

        if ($stream === false) {
            throw new UnableToReadFile(error_get_last()['message']);
        }

        return $stream;
    }

    /**
     * Sets the HTTP context options.
     *
     * @param array $context The context options
     */
    public function setContext(array $context)
    {
        $this->context = $context;
    }

    /**
     * @inheritdoc
     */
    public function setVisibility($path, $visibility): void
    {
        throw new BadMethodCallException('HttpAdapter does not support visibility. Path: ' . $path . ', visibility: ' . $visibility);
    }

    /**
     * @inheritdoc
     */
    public function write($path, $contents, Config $config):void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * @inheritdoc
     */
    public function writeStream($path, $contents, Config $config):void
    {
        throw new BadMethodCallException('Not implemented');
    }

    /**
     * Returns the URL to perform an HTTP request.
     *
     * @param string $path
     *
     * @return string
     */
    protected function buildUrl(string $path): string
    {
        $path = str_replace('%2F', '/', $path);
        $path = str_replace(' ', '%20', $path);

        return rtrim($this->base, '/') . '/' . $path;
    }

    /**
     * Performs a HEAD request.
     *
     * @param string $path
     *
     * @return array|false
     */
    protected function head(string $path): bool|array
    {
        $defaults = stream_context_get_options(stream_context_get_default());
        $options = $this->context;

        if ($this->supportsHead) {
            $options['http']['method'] = 'HEAD';
        }

        stream_context_set_default($options);

        $headers = get_headers($this->buildUrl($path), 1);

        stream_context_set_default($defaults);

        if ($headers === false || !str_contains($headers[0], ' 200')) {
            return false;
        }

        return array_change_key_case($headers);
    }

    /**
     * Parses the timestamp out of headers.
     *
     * @param array $headers
     *
     * @return ?int
     */
    protected function parseTimestamp(array $headers): int|null
    {
        if (isset($headers['last-modified'])) {
            return strtotime($headers['last-modified']);
        }

        return null;
    }

    /**
     * Parses metadata out of response headers.
     *
     * @param string $path
     * @param array  $headers
     *
     * @return FileAttributes
     */
    #[Pure] protected function parseMetadata(string $path, array $headers): FileAttributes
    {
         $mimeType = $this->parseMimeType($path, $headers);

        $timestamp = $this->parseTimestamp($headers);

        if (isset($headers['content-length']) && is_numeric($headers['content-length'])) {
           $size = (int) $headers['content-length'];
        }

        return new FileAttributes($path, $size??null, $this->visibility, $timestamp??null, $mimeType??null);
    }

    /**
     * Parses the mimetype out of response headers.
     *
     * @param string $path
     * @param array  $headers
     *
     * @return ?string
     */
    protected function parseMimeType(string $path, array $headers): ?string
    {
        if (isset($headers['content-type'])) {
            list($mimetype) = explode(';', $headers['content-type'], 2);

            return trim($mimetype);
        }

        return null;
    }
}
