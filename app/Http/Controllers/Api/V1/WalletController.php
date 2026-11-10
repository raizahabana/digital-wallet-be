<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Wallet\DepositFunds;
use App\Actions\Wallet\TransferFunds;
use App\DataTransferObjects\DepositData;
use App\DataTransferObjects\TransferData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DepositRequest;
use App\Http\Requests\Api\V1\TransferRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransferResource;
use App\Http\Resources\WalletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request): WalletResource
    {
        $wallet = $request->user()->wallet()
            ->with(['transactions' => fn ($q) => $q->latest('created_at')->latest('id')->limit(15)])
            ->firstOrFail();

        return new WalletResource($wallet);
    }

    public function deposit(DepositRequest $request, DepositFunds $action): JsonResponse
    {
        $transaction = $action->execute(DepositData::fromRequest($request));

        return (new TransactionResource($transaction))->response()->setStatusCode(201);
    }

    public function transfer(TransferRequest $request, TransferFunds $action): JsonResponse
    {
        $result = $action->execute(TransferData::fromRequest($request));

        return (new TransferResource($result))->response()->setStatusCode(201);
    }
}
