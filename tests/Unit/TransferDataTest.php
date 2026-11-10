<?php

namespace Tests\Unit;

use App\DataTransferObjects\TransferData;
use App\DataTransferObjects\TransferResult;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class TransferDataTest extends TestCase
{
    public function test_the_input_dto_exposes_what_it_was_built_with(): void
    {
        $sender = new User();
        $data = new TransferData($sender, 'bob@example.com', '5.00');

        $this->assertSame($sender, $data->sender);
        $this->assertSame('bob@example.com', $data->recipientEmail);
        $this->assertSame('5.00', $data->amount);
    }

    public function test_dtos_are_immutable(): void
    {
        $result = new TransferResult('ref', 'bob@example.com', '5.00');

        $this->expectException(\Error::class);

        $result->amount = '999.00'; // @phpstan-ignore-line
    }
}
