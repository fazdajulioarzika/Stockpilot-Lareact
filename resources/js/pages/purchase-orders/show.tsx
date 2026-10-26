import { Head, Link, router } from "@inertiajs/react";
import {
    Ban,
    Check,
    Lock,
    PackageCheck,
    Pencil,
    Send,
    Trash2,
} from "lucide-react";
import { useState } from "react";

import { PurchaseOrderStatusBadge } from "@/components/purchase-orders/status-badge";
import { StatusBadge } from "@/components/stock-documents/status-badge";
import {
    formatDateTime,
    formatMoney,
    formatQty,
} from "@/components/stock-documents/types";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Item = {
    id: number;
    quantity: string;
    unit_cost: string;
    qty_received: string;
    outstanding: string;
    line_total: string;
    product: { sku: string; name: string; unit: { symbol: string } | null };
};

type Doc = {
    id: number;
    number: string;
    status: string;
    reference: string | null;
    notes: string | null;
    expected_date: string | null;
    submitted_at: string | null;
    approved_at: string | null;
    closed_at: string | null;
    close_reason: string | null;
    completed_at: string | null;
    created_at: string;
    total: string;
    supplier: { name: string };
    warehouse: { code: string; name: string };
    creator: { name: string };
    submitter: { name: string } | null;
    approver: { name: string } | null;
    closer: { name: string } | null;
    items: Item[];
};

type Receipt = {
    id: number;
    number: string;
    status: string;
    is_reversed: boolean;
    items_count: number;
    posted_at: string | null;
    created_at: string;
};

type Can = {
    update: boolean;
    delete: boolean;
    submit: boolean;
    approve: boolean;
    receive: boolean;
    close: boolean;
    cancel: boolean;
};

type Props = { document: Doc; receipts: Receipt[]; can: Can };

type Confirm = {
    title: string;
    description: string;
    label: string;
    url: string;
    method: "post" | "delete";
    destructive?: boolean;
};

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-sm font-medium">{children}</dd>
        </div>
    );
}

export default function PurchaseOrdersShow({
    document: doc,
    receipts,
    can,
}: Props) {
    const base = `/purchase-orders/${doc.id}`;
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [closeOpen, setCloseOpen] = useState(false);
    const [reason, setReason] = useState("");
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    const showReceived =
        doc.status !== "draft" &&
        doc.status !== "submitted" &&
        doc.status !== "cancelled";

    function runConfirm() {
        if (!confirm) return;
        setBusy(true);

        const options = {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setConfirm(null);
            },
        };

        if (confirm.method === "delete") {
            router.delete(confirm.url, options);
        } else {
            router.post(confirm.url, {}, options);
        }
    }

    function createReceipt() {
        setBusy(true);
        router.post(`${base}/receipts`, {}, { onFinish: () => setBusy(false) });
    }

    function submitClose() {
        setBusy(true);
        setError(undefined);

        router.post(
            `${base}/close`,
            { reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCloseOpen(false);
                    setReason("");
                },
                onError: (e) => setError(e.reason ?? Object.values(e)[0]),
                onFinish: () => setBusy(false),
            }
        );
    }

    return (
        <>
            <Head title={doc.number} />

            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-3">
                        <h1 className="font-mono text-xl font-semibold">
                            {doc.number}
                        </h1>
                        <PurchaseOrderStatusBadge status={doc.status} />
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link href={`${base}/edit`}>
                                    <Pencil className="mr-1 size-4" /> Ubah
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setConfirm({
                                        title: `Hapus draft ${doc.number}?`,
                                        description:
                                            "Draft akan dihapus permanen.",
                                        label: "Hapus",
                                        url: base,
                                        method: "delete",
                                        destructive: true,
                                    })
                                }
                            >
                                <Trash2 className="mr-1 size-4" /> Hapus draft
                            </Button>
                        )}
                        {can.submit && (
                            <Button
                                onClick={() =>
                                    setConfirm({
                                        title: `Ajukan ${doc.number}?`,
                                        description:
                                            "Setelah diajukan, item, harga, supplier, dan gudang tidak dapat diubah.",
                                        label: "Ya, ajukan",
                                        url: `${base}/submit`,
                                        method: "post",
                                    })
                                }
                            >
                                <Send className="mr-1 size-4" /> Ajukan
                            </Button>
                        )}
                        {can.approve && (
                            <Button
                                onClick={() =>
                                    setConfirm({
                                        title: `Setujui ${doc.number}?`,
                                        description: `Pesanan senilai ${formatMoney(
                                            doc.total
                                        )} ke ${
                                            doc.supplier.name
                                        } akan boleh diterima di ${
                                            doc.warehouse.code
                                        }.`,
                                        label: "Ya, setujui",
                                        url: `${base}/approve`,
                                        method: "post",
                                    })
                                }
                            >
                                <Check className="mr-1 size-4" /> Setujui
                            </Button>
                        )}
                        {can.receive && (
                            <Button onClick={createReceipt} disabled={busy}>
                                <PackageCheck className="mr-1 size-4" /> Buat
                                penerimaan
                            </Button>
                        )}
                        {can.close && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setReason("");
                                    setError(undefined);
                                    setCloseOpen(true);
                                }}
                            >
                                <Lock className="mr-1 size-4" /> Tutup PO
                            </Button>
                        )}
                        {can.cancel && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setConfirm({
                                        title: `Batalkan ${doc.number}?`,
                                        description:
                                            "PO dibatalkan sebelum ada barang diterima. Tindakan ini tidak dapat dibatalkan.",
                                        label: "Ya, batalkan",
                                        url: `${base}/cancel`,
                                        method: "post",
                                        destructive: true,
                                    })
                                }
                            >
                                <Ban className="mr-1 size-4" /> Batalkan
                            </Button>
                        )}
                    </div>
                </div>

                {doc.close_reason && (
                    <div className="rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                        <span className="font-medium">
                            PO ditutup sebelum seluruh pesanan diterima
                        </span>
                        {doc.closer ? ` oleh ${doc.closer.name}` : ""} pada{" "}
                        {formatDateTime(doc.closed_at)}. Alasan:{" "}
                        {doc.close_reason}
                    </div>
                )}

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Field label="Supplier">{doc.supplier.name}</Field>
                    <Field label="Gudang tujuan">
                        {doc.warehouse.code} - {doc.warehouse.name}
                    </Field>
                    <Field label="Referensi">{doc.reference ?? "-"}</Field>
                    <Field label="Perkiraan tiba">
                        {doc.expected_date ?? "-"}
                    </Field>
                    <Field label="Catatan">{doc.notes ?? "-"}</Field>
                    <Field label="Total pesanan">
                        {formatMoney(doc.total)}
                    </Field>
                    <Field label="Dibuat">
                        {formatDateTime(doc.created_at)} oleh {doc.creator.name}
                    </Field>
                    <Field label="Diajukan">
                        {doc.submitted_at
                            ? `${formatDateTime(doc.submitted_at)} oleh ${
                                  doc.submitter?.name ?? "-"
                              }`
                            : "-"}
                    </Field>
                    <Field label="Disetujui">
                        {doc.approved_at
                            ? `${formatDateTime(doc.approved_at)}${
                                  doc.approver
                                      ? ` oleh ${doc.approver.name}`
                                      : " (otomatis)"
                              }`
                            : "-"}
                    </Field>
                    <Field label="Selesai">
                        {formatDateTime(doc.completed_at)}
                    </Field>
                </dl>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>SKU</TableHead>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">
                                    Dipesan
                                </TableHead>
                                {showReceived && (
                                    <>
                                        <TableHead className="text-right">
                                            Diterima
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Sisa
                                        </TableHead>
                                    </>
                                )}
                                <TableHead>Satuan</TableHead>
                                <TableHead className="text-right">
                                    Harga satuan
                                </TableHead>
                                <TableHead className="text-right">
                                    Subtotal
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {doc.items.map((i) => (
                                <TableRow key={i.id}>
                                    <TableCell className="font-mono text-xs">
                                        {i.product.sku}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {i.product.name}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatQty(i.quantity)}
                                    </TableCell>
                                    {showReceived && (
                                        <>
                                            <TableCell className="text-right">
                                                {formatQty(i.qty_received)}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatQty(i.outstanding)}
                                            </TableCell>
                                        </>
                                    )}
                                    <TableCell>
                                        {i.product.unit?.symbol ?? "-"}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(i.unit_cost)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(i.line_total)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                {receipts.length > 0 && (
                    <div className="grid gap-2">
                        <h2 className="text-base font-semibold">
                            Penerimaan barang
                        </h2>
                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Nomor</TableHead>
                                        <TableHead className="text-right">
                                            Item
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Diposting</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {receipts.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell className="font-mono text-xs">
                                                <Link
                                                    href={`/goods-receipts/${r.id}`}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {r.number}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {r.items_count}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={
                                                        r.is_reversed
                                                            ? "reversed"
                                                            : r.status
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell>
                                                {formatDateTime(r.posted_at)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                )}

                <div>
                    <Button variant="ghost" asChild>
                        <Link href="/purchase-orders">Kembali ke daftar</Link>
                    </Button>
                </div>
            </div>

            <Dialog
                open={!!confirm}
                onOpenChange={(open) => !open && setConfirm(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{confirm?.title}</DialogTitle>
                        <DialogDescription>
                            {confirm?.description}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirm(null)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant={
                                confirm?.destructive ? "destructive" : "default"
                            }
                            onClick={runConfirm}
                            disabled={busy}
                        >
                            {busy ? "Memproses..." : confirm?.label}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={closeOpen} onOpenChange={setCloseOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Tutup {doc.number}?</DialogTitle>
                        <DialogDescription>
                            Gunakan ini jika supplier tidak akan mengirim sisa
                            pesanan. PO berstatus Diterima dan tidak dapat
                            menerima barang lagi. Alasan wajib diisi dan
                            tercatat.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="close-reason">Alasan penutupan</Label>
                        <textarea
                            id="close-reason"
                            rows={3}
                            maxLength={255}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        {error && (
                            <p className="text-sm text-destructive">{error}</p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setCloseOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submitClose}
                            disabled={busy || reason.trim() === ""}
                        >
                            {busy ? "Memproses..." : "Tutup PO"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

PurchaseOrdersShow.layout = {
    breadcrumbs: [
        { title: "Purchase Order", href: "/purchase-orders" },
        { title: "Detail", href: "#" },
    ],
};
