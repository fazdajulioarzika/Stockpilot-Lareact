<?php

namespace App\Http\Requests;

use App\Models\StockIssue;

class StockIssueRequest extends StockDocumentRequest
{
    protected function documentClass(): string
    {
        return StockIssue::class;
    }

    protected function routeParameter(): string
    {
        return 'stock_issue';
    }

    protected function extraRules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }

    protected function extraAttributes(): array
    {
        return ['reason' => 'alasan'];
    }
}
