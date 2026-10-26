<?php

return [
    // true: transfer harus disetujui Manager/Admin sebelum boleh dikirim.
    'transfer_requires_approval' => (bool) env('TRANSFER_REQUIRES_APPROVAL', true),
    // true: PO harus disetujui Manager/Admin lain sebelum boleh menerima barang.
    'po_requires_approval' => (bool) env('PO_REQUIRES_APPROVAL', true),
];
