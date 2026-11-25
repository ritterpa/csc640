<?php

namespace App\Http\Resources;

use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats a performance metric: same fields as Stage 1. metricValue is a
 * JSON number (the model casts the DECIMAL column to float).
 *
 * @mixin \App\Models\PerformanceMetric
 */
class MetricResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'metricId'     => (int) $this->MetricID,
            'runId'        => $this->RunID,
            'timestampUtc' => Format::timestamp($this->TimestampUtc),
            'metricName'   => $this->MetricName,
            'metricValue'  => (float) $this->MetricValue,
            'unit'         => $this->Unit,
            'category'     => $this->Category,
            'jsonData'     => Format::json($this->JsonData),
        ];
    }
}
