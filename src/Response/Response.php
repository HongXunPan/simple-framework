<?php

namespace HongXunPan\Framework\Response;

class Response implements ResponseContract
{
    public function __construct(private $content, private $headers = [])
    {
        $this->formatContent();
        $this->setHeader();
    }

    private function setHeader(): void
    {
        foreach ($this->headers as $key => $value) {
            header($key . ': ' . $value);
        }
    }

    private function formatContent(): void
    {
        if (is_array($this->content)) {
            $this->headers['Content-Type'] = 'application/json';
            $this->content = json_encode($this->content);
        }
    }

    public function send(): void
    {
        echo $this->content;
    }
}
