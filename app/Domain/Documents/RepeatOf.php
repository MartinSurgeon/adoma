<?php

namespace App\Domain\Documents;

use App\Domain\Support\Money;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * "Record another like this": the starting point for a new form, taken from an earlier transaction.
 *
 * Staff often record the same person, the same account and the same kind of gold again and again.
 * This carries over who it was with, the cash account, the note and the gold's form and price.
 * It never carries amounts, weights, dates or anything that makes a transaction its own, and it
 * never reads a transaction from another office: the document is found through the usual scopes.
 */
final class RepeatOf
{
    /**
     * @return array{ledger: ?array{id: int, name: string, kind_label: string, balance: string}, cash_ledger_id: ?int, note: ?string, form: ?string, price: ?string}|null
     */
    public static function from(Request $request, DocumentType $type): ?array
    {
        $id = $request->query('like');

        if (! is_scalar($id) || ! ctype_digit((string) $id)) {
            return null;
        }

        $branchId = app(TenantContext::class)->branchId();

        $document = Document::query()
            ->with(['ledger', 'goldLine'])
            ->where('type', $type->value)
            ->where('branch_id', $branchId)
            ->find((int) $id);

        if ($document === null || $document->ledger === null || ! $document->ledger->is_active) {
            return null;
        }

        $ledger = $document->ledger;

        return [
            'ledger' => [
                'id' => $ledger->id,
                'name' => $ledger->name,
                'kind_label' => $ledger->kind->label(),
                'balance' => LedgerBalances::describe($ledger->kind, LedgerBalances::cents([$ledger->id])[$ledger->id]),
            ],
            'cash_ledger_id' => $document->cash_ledger_id,
            'note' => $document->note,
            'form' => $document->goldLine?->form,
            'price' => $document->goldLine ? Money::decimal(Money::fromDb($document->goldLine->price)) : null,
        ];
    }
}
