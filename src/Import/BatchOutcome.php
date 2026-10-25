<?php

declare(strict_types=1);

namespace Tmonier\SyliusErpFlatfilePlugin\Import;

enum BatchOutcome: string
{
    /** Every line was applied. */
    case Applied = 'applied';

    /** Stock only: the valid lines were applied, the others are in the report. */
    case Partial = 'partial';

    /** Nothing was applied: a price batch with an invalid line, an unreadable file, or no valid line. */
    case Rejected = 'rejected';

    /** The batch (same name, same checksum) was processed before: ignored. */
    case Duplicate = 'duplicate';
}
