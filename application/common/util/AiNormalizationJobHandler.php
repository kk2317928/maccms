<?php

namespace app\common\util;

class AiNormalizationJobHandler
{
    private $pipeline;

    public function __construct(AiNormalizationPipeline $pipeline)
    {
        $this->pipeline = $pipeline;
    }

    public function __invoke(array $payload, array $job): array
    {
        return $this->pipeline->handle($payload, $job);
    }
}
