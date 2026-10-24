<?php

return [
    // true: transfer harus disetujui Manager/Admin sebelum boleh dikirim.
    'transfer_requires_approval' => (bool) env('TRANSFER_REQUIRES_APPROVAL', true),
];