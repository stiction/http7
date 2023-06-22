<?php

namespace Stiction\Http7;

use DomainException;
use Exception;
use LogicException;
use RuntimeException;

class CurlCat
{
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_PATCH = 'PATCH';
    const METHOD_DELETE = 'DELETE';
    const METHOD_OPTIONS = 'OPTIONS';
    const METHOD_HEAD = 'HEAD';
    const METHOD_TRACE = 'TRACE';

    const TYPE_JSON = 'application/json';

    const HEADER_CONTENT_TYPE = 'Content-Type';

    protected $ch;

    protected $headers = [];
    protected $options = [];
    protected $ignoreHttpCode = false;
    protected $tryTimes = 1;
    protected $tryInterval = 0;
    protected $maxFileSize = -1;

    protected $done = false;
    protected $tries = 0;
    protected $resHeaders = [];
    protected $resExps = [];

    public function __construct()
    {
        $this->initCurl();
    }

    public function __destruct()
    {
        curl_close($this->ch);
    }

    public function __clone()
    {
        $this->initCurl();
        $this->reset();
    }

    private function initCurl()
    {
        $this->ch = curl_init();
        if (!$this->ch) {
            throw new RuntimeException('curl_init');
        }
    }

    private function reset()
    {
        $this->done = false;
        $this->tries = 0;
        $this->resHeaders = [];
        $this->resExps = [];
    }

    public function method(string $method)
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = $method;
        return $this;
    }

    public function get()
    {
        return $this->method(self::METHOD_GET);
    }

    public function post()
    {
        return $this->method(self::METHOD_POST);
    }

    public function put()
    {
        return $this->method(self::METHOD_PUT);
    }

    public function patch()
    {
        return $this->method(self::METHOD_PATCH);
    }

    public function delete()
    {
        return $this->method(self::METHOD_DELETE);
    }

    public function url(string $url, array $params = [])
    {
        if (count($params) > 0) {
            $url = $this->buildUrl($url, $params);
        }
        $this->options[CURLOPT_URL] = $url;
        return $this;
    }

    /**
     * set or remove a request header
     *
     * no canonicalization for the header name, it should be done elsewhere. Content-Type vs. content-type, TOKEN vs. Token, etc.
     *
     * @param string $name header name, case sensitive, no canonicalization
     * @param string|null $value header value, null means to remove
     * @return static
     */
    public function header(string $name, $value)
    {
        if (is_null($value)) {
            unset($this->headers[$name]);
        } else {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    public function userAgent(string $agent)
    {
        return $this->header('User-Agent', $agent);
    }

    public function encoding(string $encoding = '')
    {
        $this->options[CURLOPT_ENCODING] = $encoding;
        return $this;
    }

    public function type(string $type)
    {
        return $this->header(self::HEADER_CONTENT_TYPE, $type);
    }

    public function body(array $fields)
    {
        $this->header(self::HEADER_CONTENT_TYPE, null);
        $this->options[CURLOPT_POSTFIELDS] = $fields;
        return $this;
    }

    public function bodyUrlencoded(string $str)
    {
        $this->header(self::HEADER_CONTENT_TYPE, null);
        $this->options[CURLOPT_POSTFIELDS] = $str;
        return $this;
    }

    public function bodyRaw(string $str, string $type = '')
    {
        $this->options[CURLOPT_POSTFIELDS] = $str;
        if ($type !== '') {
            $this->type($type);
        }
        return $this;
    }

    public function bodyJson(array $data)
    {
        $str = json_encode($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(json_last_error_msg());
        }
        return $this->bodyRaw($str, self::TYPE_JSON);
    }

    public function setopt(int $option, $value)
    {
        $this->options[$option] = $value;
        return $this;
    }

    public function unsetopt(int $option)
    {
        unset($this->options[$option]);
        return $this;
    }

    public function timeout(int $seconds)
    {
        $this->options[CURLOPT_TIMEOUT] = $seconds;
        return $this;
    }

    public function timeoutMs(int $milliseconds)
    {
        $this->options[CURLOPT_TIMEOUT_MS] = $milliseconds;
        return $this;
    }

    public function maxSize(int $size)
    {
        $this->maxFileSize = ($size >= 0) ? $size : -1;
        return $this;
    }

    public function sslVerify(string $caFile = '')
    {
        if ($caFile === '') {
            $caFile = __DIR__ . DIRECTORY_SEPARATOR . 'cacert-2022-07-19.pem';
        }
        $this->options[CURLOPT_CAINFO] = $caFile;
        $this->options[CURLOPT_SSL_VERIFYPEER] = true;
        return $this;
    }

    public function followLocation(bool $follow = true)
    {
        $this->options[CURLOPT_FOLLOWLOCATION] = $follow;
        return $this;
    }

    public function maxRedirects(int $max)
    {
        $this->options[CURLOPT_MAXREDIRS] = $max;
        return $this;
    }

    public function ignoreCode(bool $ignore = true)
    {
        $this->ignoreHttpCode = $ignore;
        return $this;
    }

    /**
     * configure retry policy.
     *
     * please notice ignoreCode() method.
     *
     * @param int $times total try times
     * @param int $interval try interval in milliseconds
     * @return static
     */
    function try(int $times, int $interval)
    {
        if ($times < 1) {
            throw new DomainException("invalid try times $times");
        }
        $this->tryTimes = $times;
        $this->tryInterval = $interval;
        return $this;
    }

    public function verbose(bool $verbose = true)
    {
        if ($verbose) {
            return $this->setopt(CURLOPT_VERBOSE, true);
        } else {
            return $this->unsetopt(CURLOPT_VERBOSE);
        }
    }

    public function fetch(): string
    {
        if ($this->done) {
            throw new LogicException('fetch done');
        }
        $this->done = true;

        $this->prepare();

        while ($this->tries < $this->tryTimes) {
            $this->tries += 1;
            try {
                return $this->do();
            } catch (Exception $e) {
                $this->resExps[] = $e;
                if ($this->tries === $this->tryTimes) {
                    throw $e;
                }
                if ($this->tryInterval > 0) {
                    usleep($this->tryInterval * 1000);
                }
            }
        }
    }

    public function fetchJson(bool $checkMime = false): array
    {
        $str = $this->fetch();
        if ($checkMime) {
            $mime = $this->resType();
            if (!$this->isMimeJson($mime)) {
                throw new RuntimeException("invalid json mime $mime");
            }
        }
        return $this->parseJson($str);
    }

    protected function buildUrl(string $url, array $params): string
    {
        $hashIndex = strpos($url, '#');
        if ($hashIndex != false) {
            $full = substr($url, 0, $hashIndex);
            $fragment = substr($url, $hashIndex + 1);
        } else {
            $full = $url;
            $fragment = '';
        }
        $searchIndex = strpos($full, '?');
        if ($searchIndex !== false) {
            if (substr($full, -1) !== '&') {
                $full .= '&';
            }
        } else {
            $full .= '?';
        }
        $full .= http_build_query($params);
        if ($fragment !== '') {
            $full .= '#' . $fragment;
        }
        return $full;
    }

    protected function prepareHeaders()
    {
        if (count($this->headers) === 0) {
            unset($this->options[CURLOPT_HTTPHEADER]);
            return;
        }

        $list = [];
        foreach ($this->headers as $key => $value) {
            $list[] = $key . ': ' . $value;
        }
        $this->options[CURLOPT_HTTPHEADER] = $list;
    }

    protected function prepare()
    {
        $this->prepareHeaders();
        $this->options[CURLOPT_RETURNTRANSFER] = true;
        $this->options[CURLOPT_HEADERFUNCTION] = [$this, 'receiveHeader'];

        foreach ($this->options as $option => $value) {
            $setOk = curl_setopt($this->ch, $option, $value);
            if (!$setOk) {
                throw new RuntimeException("curl_setopt $option");
            }
        }
    }

    protected function parseJson(string $str): array
    {
        $data = json_decode($str, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new RuntimeException('json is not array nor object');
        }
        return $data;
    }

    protected function isMimeJson(string $mime): bool
    {
        $mime = strtolower($mime);
        if (strpos($mime, self::TYPE_JSON) === 0) {
            return true;
        }
        return false;
    }

    protected function receiveHeader($ch, string $header)
    {
        $len = strlen($header);

        $parts = explode(':', $header, 2);
        if (count($parts) !== 2) {
            if (stripos($header, 'HTTP') !== false) {
                $this->resHeaders = [];
            }
            return $len;
        }
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        $name = strtolower($name);
        if (isset($this->resHeaders[$name])) {
            $this->resHeaders[$name][] = $value;
        } else {
            $this->resHeaders[$name] = [$value];
        }
        return $len;
    }

    function do(): string
    {
        $this->resHeaders = [];

        $limitSize = $this->maxFileSize >= 0;
        $buffer = '';
        if ($limitSize) {
            curl_setopt($this->ch, CURLOPT_WRITEFUNCTION, function ($ch, string $str) use (&$buffer) {
                $len = strlen($str);
                if ($len <= $this->maxFileSize - strlen($buffer)) {
                    $buffer .= $str;
                    return $len;
                }
                return 0;
            });
        }

        $ret = curl_exec($this->ch);
        if ($ret === false) {
            $message = sprintf('curl error (%d): %s', curl_errno($this->ch), curl_error($this->ch));
            throw new RuntimeException($message);
        }
        $text = $limitSize ? $buffer : $ret;

        if (!$this->ignoreHttpCode) {
            $code = $this->resCode();
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException("response code $code");
            }
        }

        return $text;
    }

    // response information

    public function resTries(): int
    {
        $this->checkDone();
        return $this->tries;
    }

    public function resInfo(int $option = 0)
    {
        $this->checkDone();
        return curl_getinfo($this->ch, $option);
    }

    public function resCode(): int
    {
        $code = $this->resInfo(CURLINFO_RESPONSE_CODE);
        return $code;
    }

    public function resType(): string
    {
        return $this->resInfo(CURLINFO_CONTENT_TYPE) ?? '';
    }

    public function resHeaderLine(string $name): string
    {
        return implode(',', $this->resHeader($name));
    }

    public function resHeader(string $name): array
    {
        $this->checkDone();
        $name = strtolower($name);
        return $this->resHeaders[$name] ?? [];
    }

    public function resAllHeaders(): array
    {
        $this->checkDone();
        return $this->resHeaders;
    }

    public function resAllHeadersLine(): array
    {
        $allHeaders = $this->resAllHeaders();
        return array_map(function ($values) {
            return implode(',', $values);
        }, $allHeaders);
    }

    /**
     * try exceptions
     *
     * @return array
     */
    public function resExceptions(): array
    {
        $this->checkDone();
        return $this->resExps;
    }

    protected function checkDone()
    {
        if (!$this->done) {
            throw new LogicException('fetch not done');
        }
    }
}
