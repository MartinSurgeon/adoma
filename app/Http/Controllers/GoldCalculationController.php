<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Document;
use App\Domain\Gold\CalculationError;
use App\Domain\Stock\LotAllocator;
use App\Domain\Support\Money;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\SaveGoldDocumentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live preview on the purchase and sale forms. It runs the very same calculator that saving uses,
 * so what a person sees while typing is what will be saved. Nothing is stored here.
 */
class GoldCalculationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $type = $request->input('type') === 'sale' ? 'sale' : 'purchase';

        $this->authorize('create', [Document::class, $type]);

        try {
            $figures = SaveGoldDocumentRequest::calculate($request->user()->tenant_id, $request->only(['form', 'weight', 'big_weight', 'water_weight', 'karat', 'price']));
        } catch (CalculationError $error) {
            return response()->json(['ok' => false, 'field' => $error->field, 'message' => $error->getMessage()]);
        }

        $response = ['ok' => true, 'figures' => $figures->toArray()];

        if ($type === 'sale') {
            $available = LotAllocator::availableH(app(TenantContext::class)->branch());
            $response['stock_ok'] = $figures->weightH <= $available;
            $response['in_stock'] = Money::format($available);
        }

        return response()->json($response);
    }
}
