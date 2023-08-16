<?php

namespace HongXunPan\Framework\Response;

class StandardResponse extends Response
{
    protected function formatContent(): void
    {
        if (is_array($this->content)) {
            if (!isset($this->content['code'])) {
                $this->content = [
                    'code' => 0,
                    'msg' => 'success',
                    'data' => $this->content,
                ];
            }
        }
        parent::formatContent();
    }
}