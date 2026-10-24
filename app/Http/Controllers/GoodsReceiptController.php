<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\PostGoodsReceipt;
use App\Http\Requests\GoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class GoodsReceiptController extends StockDocumentController
{
    protected function modelClass(): string
    {
        return GoodsReceipt::class;
    }

    protected function config(): array
    {
        return [
            'title' => 'Barang Masuk',
            'basePath' => '/goods-receipts',
            'route' => 'goods-receipts',
            'view' => 'goods-receipts',
            'type' => 'receipt',
        ];
    }

    protected function headerFields(): array
    {
        return ['supplier_id'];
    }

    protected function extraRelations(): array
    {
        return ['supplier:id,name'];
    }

    protected function extraFormProps(): array
    {
        return [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    protected function runPost(Model $doc, User $user): void
    {
        app(PostGoodsReceipt::class)->execute($doc, $user);
    }

    public function index(Request $request): Response
    {
        return $this->listDocuments($request);
    }

    public function create(Request $request): Response
    {
        return $this->showForm($request);
    }

    public function store(GoodsReceiptRequest $request): RedirectResponse
    {
        return $this->storeDocument($request);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        return $this->showDocument($request, $goodsReceipt);
    }

    public function edit(Request $request, GoodsReceipt $goodsReceipt): Response
    {
        return $this->showForm($request, $goodsReceipt);
    }

    public function update(GoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        return $this->updateDocument($request, $goodsReceipt);
    }

    public function destroy(GoodsReceipt $goodsReceipt): RedirectResponse
    {
        return $this->destroyDocument($goodsReceipt);
    }

    public function post(Request $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        return $this->postDocument($request, $goodsReceipt);
    }
}
