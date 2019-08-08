<?php

namespace VarnishAdmin;

use Exception;
use VarnishAdmin\commands\Commands;
use VarnishAdmin\commands\CommandsVersion3;
use VarnishAdmin\commands\CommandsVersion4;
use VarnishAdmin\commands\CommandsVersion5;

class VarnishAdminSocket implements VarnishAdmin
{
    const DEFAULT_TIMEOUT = 5;
    const NEW_LINE = "\n";
    const SUCCESS_STATUS = 200;

    /**
     * Secret to use in authentication challenge.
     *
     * @var string
     */
    protected $secret;
    /**
     * Major version of Varnish top which you're connecting; 3 or 4.
     *
     * @var int
     */
    protected $version;

    /** @var Commands */
    private $commands;
    /** @var Socket */
    private $socket;
    /** @var ServerAddress */
    private $serverAddress;

    /**
     * Constructor.
     *
     * @param string $host
     * @param int $port
     * @param string $version
     *
     * @throws \Exception
     */
    public function __construct($host = null, $port = null, $version = null)
    {
        $this->calculateVersion($version);
        $this->setDefaultCommands();
        $this->serverAddress = new ServerAddress($host, $port);
        $this->socket = new Socket();
    }

    /**
     * @param $version
     * @throws Exception
     */
    private function calculateVersion($version)
    {
        $this->setVersion($version);
        $this->checkSupportedVersion();
    }

    private function setVersion($version)
    {
        if (empty($version)) {
            $version = Commands::DEFAULT_VERSION;
        }
        $versionSplit = explode('.', $version, Commands::DEFAULT_VERSION);
        $this->version = isset($versionSplit[0]) ? (int)$versionSplit[0] : Commands::DEFAULT_VERSION;
    }

    private function checkSupportedVersion()
    {
        $supportedVersions = array_keys($this->getCommandVersionMap());
        sort($supportedVersions);
        if (!in_array($this->version, $supportedVersions, true)) {
            $lastIndex = count($supportedVersions) - 1;
            $supportedVersions[$lastIndex] = "and {$supportedVersions[$lastIndex]}";
            $supportedVersionString = implode(', ', $supportedVersions);

            throw new \Exception("Only versions $supportedVersionString of Varnish are supported");
        }
    }

    private function getCommandVersionMap()
    {
        return [
            3 => CommandsVersion3::class,
            4 => CommandsVersion4::class,
            5 => CommandsVersion5::class,
            6 => CommandsVersion5::class, // No changes in version 6
        ];
    }

    private function setDefaultCommands()
    {
        $versions = $this->getCommandVersionMap();

        if (isset($versions[$this->version])) {
            $class = $versions[$this->version];
            $this->commands = new $class();
        }
    }

    /**
     * Connect to admin socket.
     *
     * @param int $timeout in seconds, defaults to 5; used for connect and reads
     * @return string the banner, in case you're interested
     * @throws Exception
     */
    public function connect($timeout = null)
    {
        if (empty($timeout)) {
            $timeout = self::DEFAULT_TIMEOUT;
        }
        $this->socket->openSocket($this->getServerAddress()->getHost(), $this->getServerAddress()->getPort(), $timeout);

        $banner = $this->socket->read($code);
        if ($this->needAuthenticate($code)) {
            $this->checkSecretIsSet();
            try {
                $authenticationData = $this->commands->getAuth() . ' ' . $this->obtainAuthenticationKey($banner);
                $banner = $this->command($authenticationData, $code, self::SUCCESS_STATUS);
            } catch (Exception $ex) {
                throw new Exception('Authentication failed');
            }
        }
        $this->checkResponse($code);

        return $banner;
    }

    /**
     * @return ServerAddress
     */
    public function getServerAddress()
    {
        return $this->serverAddress;
    }

    /**
     * @param $code
     * @return bool
     */
    private function needAuthenticate($code)
    {
        return $code === 107;
    }

    private function checkSecretIsSet()
    {
        if (empty($this->secret)) {
            throw new \Exception('Authentication required; see VarnishAdminSocket::setSecret');
        }
    }

    /**
     * @param $banner
     * @return string
     */
    private function obtainAuthenticationKey($banner)
    {
        $challenge = substr($banner, 0, 32);
        $auth_string = $challenge . self::NEW_LINE . $this->secret . $challenge . self::NEW_LINE;
        $response = hash('sha256', $auth_string);
        return $response;
    }

    /**
     * Write a command to the socket with a trailing line break and get response straight away.
     *
     * @param string $cmd
     * @param $code
     * @param int $ok
     * @return string
     * @throws Exception
     * @internal param $string
     */
    protected function command($cmd, &$code = '', $ok = 200)
    {
        if (!$this->serverAddress->getHost()) {
            return null;
        }
        if (!empty($cmd)) {
            $this->socket->write($cmd);
        }
        $this->socket->write(self::NEW_LINE);
        $response = $this->socket->read($code);
        if ($code !== $ok) {
            $responseParsed = $this->parseResponse($response);
            throw new Exception(sprintf("%s command responded %d:\n > %s", $cmd, $code, $responseParsed), $code);
        }
        return $response;
    }

    /**
     * @param $response
     * @return string
     */
    protected function parseResponse($response)
    {
        $response = implode(self::NEW_LINE . " > ", explode(self::NEW_LINE, trim($response)));
        return $response;
    }

    /**
     * @param $code
     * @throws Exception
     */
    private function checkResponse($code)
    {
        if ($this->isBad($code)) {
            throw new \Exception(sprintf('Bad response from varnishadm on %s:%s',
                $this->serverAddress->getHost(),
                $this->serverAddress->getPort()));
        }
    }

    /**
     * @param $code
     * @return bool
     */
    private function isBad($code)
    {
        return $code !== self::SUCCESS_STATUS;
    }

    /**
     * Shortcut to purge function.
     *
     * @see https://www.varnish-cache.org/docs/4.0/users-guide/purging.html
     *
     * @param string $expr is a purge expression in form "<field> <operator> <arg> [&& <field> <oper> <arg>]..."
     *
     * @return string
     */
    public function purge($expr)
    {
        return $this->command($this->commands->getPurgeCommand() . ' ' . $expr);
    }

    /**
     * Shortcut to purge.url function.
     *
     * @see https://www.varnish-cache.org/docs/4.0/users-guide/purging.html
     *
     * @param string $url is a url to purge
     *
     * @return string
     */
    public function purgeUrl($url)
    {
        return $this->command($this->commands->getPurgeUrlCommand() . ' ' . $url);
    }

    /**
     * Graceful close, sends quit command.
     */
    public function quit()
    {
        try {
            $this->command($this->commands->getQuit(), $code, 500);
        } catch (Exception $Ex) {
            // silent fail - force close of socket
        }
        $this->close();
    }

    /**
     * Brutal close, doesn't send quit command to varnishadm.
     */
    public function close()
    {
        $this->socket->close();
        $this->socket = null;
    }

    /**
     * @return bool
     */
    public function start()
    {
        if ($this->status()) {
            $this->generateErrorMessage(sprintf('varnish host already started on %s:%s',
                $this->serverAddress->getHost(), $this->serverAddress->getPort()));

            return true;
        }
        $this->command($this->commands->getStart());

        return true;
    }

    public function status()
    {
        try {
            $response = $this->command($this->commands->getStatus());

            return $this->isRunning($response);
        } catch (\Exception $Ex) {
            return false;
        }
    }

    protected function isRunning($response)
    {
        if (!preg_match('/Child in state (\w+)/', $response, $result)) {
            return false;
        }

        return $result[1] === 'running' ? true : false;
    }

    private function generateErrorMessage($msg)
    {
        trigger_error($msg, E_USER_NOTICE);
    }

    /**
     * Set authentication secret.
     * Warning: may require a trailing newline if passed to varnishadm from a text file.
     *
     * @param string
     */
    public function setSecret($secret)
    {
        $this->secret = $secret;
    }

    /**
     * @return bool
     */
    public function stop()
    {
        if (!$this->status()) {
            $this->generateErrorMessage(sprintf('varnish host already stopped on %s:%s',
                $this->serverAddress->getHost(), $this->serverAddress->getPort()));

            return true;
        }

        $this->command($this->commands->getStop());

        return true;
    }

    /**
     * @return Socket
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * @param Socket $socket
     */
    public function setSocket($socket)
    {
        $this->socket = $socket;
    }
}
