<?php

namespace HongXunPan\Framework\Response;

/**
 * @deprecated 请改用 simple-api 提供的标准 JSON 响应，并在项目侧扩展响应内容。
 */
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
