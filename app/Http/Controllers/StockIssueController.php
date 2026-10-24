<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\PostStockIssue;
use App\Http\Requests\StockIssueRequest;
use App\Models\StockIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class StockIssueController extends StockDocumentController
{
    protected function modelClass(): string
    {
        return StockIssue::class;
    }

    protected function config(): array
    {
        return [
            'title' => 'Barang Keluar',
            'basePath' => '/stock-issues',
            'route' => 'stock-issues',
            'view' => 'stock-issues',
            'type' => 'issue',
        ];
    }

    protected function headerFields(): array
    {
        return ['reason'];
    }

    protected function runPost(Model $doc, User $user): void
    {
        app(PostStockIssue::class)->execute($doc, $user);
    }

    public function index(Request $request): Response
    {
        return $this->listDocuments($request);
    }

    public function create(Request $request): Response
    {
        return $this->showForm($request);
    }

    public function store(StockIssueRequest $request): RedirectResponse
    {
        return $this->storeDocument($request);
    }

    public function show(Request $request, StockIssue $stockIssue): Response
    {
        return $this->showDocument($request, $stockIssue);
    }

    public function edit(Request $request, StockIssue $stockIssue): Response
    {
        return $this->showForm($request, $stockIssue);
    }

    public function update(StockIssueRequest $request, StockIssue $stockIssue): RedirectResponse
    {
        return $this->updateDocument($request, $stockIssue);
    }

    public function destroy(StockIssue $stockIssue): RedirectResponse
    {
        return $this->destroyDocument($stockIssue);
    }

    public function post(Request $request, StockIssue $stockIssue): RedirectResponse
    {
        return $this->postDocument($request, $stockIssue);
    }
}
