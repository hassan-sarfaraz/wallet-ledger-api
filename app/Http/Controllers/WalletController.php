<?php

namespace App\Http\Controllers;

use App\Exceptions\WalletException;
use App\Http\Requests\WalletMutationRequest;
use App\Services\WalletLedger;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function credit(WalletMutationRequest $request, WalletLedger $ledger): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['user_id' => $data['user_id'], 'balance' => $ledger->credit($data['user_id'], $data['amount'])]);
    }

    public function debit(WalletMutationRequest $request, WalletLedger $ledger): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['user_id' => $data['user_id'], 'balance' => $ledger->debit($data['user_id'], $data['amount'])]);
    }

    public function balance(string $user_id, WalletLedger $ledger): JsonResponse
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $user_id)) {
            throw new WalletException('invalid_request', 'user_id must contain 1 to 64 letters, digits, underscores or hyphens.', 400);
        }

        return response()->json(['user_id' => $user_id, 'balance' => $ledger->balance($user_id)]);
    }
}
