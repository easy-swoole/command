<?php


namespace EasySwoole\Command;


use EasySwoole\Command\AbstractInterface\CallerInterface;

class Caller implements CallerInterface
{
    private $command;
    private $params;
    private $originArgs;

    public function getOriginArgs(){
        return $this->originArgs;
    }

    public function getOneParam($remove = true)
    {
        if (!$this->params) {
            return [];
        }
        reset($this->params);
        $key    = key($this->params);
        $value  = current($this->params);
        if ($remove === true) {
            array_shift($this->params);
            array_shift($this->originArgs);
        }
        return [$key => $value];
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setCommand(string $command)
    {
        $this->command = $command;
    }

    public function setParams($params)
    {
        $formatParams = [];
        foreach ((array)$params as $key => $param) {
            if (is_numeric($key)) {
                if (strpos($param, '=') !== false) {
                    [$k, $v] = explode('=', $param, 2);
                    $formatParams[$k] = $v;
                } else if (strpos($param, ':') !== false) {
                    [$k, $v] = explode(':', $param, 2);
                    $formatParams[$k] = $v;
                } else {
                    $formatParams[$param] = true;
                }
            } else {
                $formatParams[$key] = $param;
            }
        }
        $this->originArgs = $params;
        $this->params = $formatParams;
    }

    public function getParams($key = null, $default = null)
    {
        if (!is_null($key)) {
            return isset($this->params[$key]) ? $this->params[$key] : $default;
        }
        return $this->params;
    }

}