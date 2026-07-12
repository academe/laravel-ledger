<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Tests\Fixtures\Enums;

use Academe\LaravelJournal\Contracts\LedgerType;
use Academe\LaravelJournal\Enums\BalanceSide;

/**
 * An application-defined ledger type: a contra-asset (e.g. accumulated
 * depreciation) sits on the asset side of the statement but carries a
 * credit-normal balance.
 */
enum ContraAssetType: string implements LedgerType
{
    case CONTRA_ASSET = 'contra-asset';

    public function normalBalance(): BalanceSide
    {
        return BalanceSide::Credit;
    }
}
