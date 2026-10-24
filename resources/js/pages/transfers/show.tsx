import { Head, Link, router } from "@inertiajs/react";
import {
    ArrowRight,
    Ban,
    Check,
    PackageCheck,
    Pencil,
    Send,
    Trash2,
    Truck,
    Undo2,
} from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { formatDateTime, formatQty } from "@/components/stock-documents/types";

import { TransferStatusBadge } from "@/components/status-badge";

type Item = {
    id: number;
    quantity: string;
    qty_sent: string;
    qty_received: string;
    qty_returned: string;
    remaining: string;
    product: { sku: string; name: string; unit: { symbol: string } | null };
};

type Doc = {
    id: number;
    number: string;
    status: string;
    reference: string | null;
    notes: string | null;
    submitted_at: string | null;
    approved_at: string | null;
    dispatched_at: string | null;
    completed_at: string | null;
    created_at: string;
    source: { code: string; name: string };
    destination: { code: string; name: string };
    creator: { name: string };
    approver: { name: string } | null;
    dispatcher: { name: string } | null;
    items: Item[];
};

type Can = {
    update: boolean;
    delete: boolean;
    submit: boolean;
    approve: boolean;
    dispatch: boolean;
    receive: boolean;
    returnToSource: boolean;
    cancel: boolean;
};

type Props = { document: Doc; can: Can };

type Confirm = {
    title: string;
    description: string;
    label: string;
    url: string;
    method: "post" | "delete";
    destructive?: boolean;
};

function newKey(): string {
    if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
        return crypto.randomUUID();
    }

    return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

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

export default function TransfersShow({ document: doc, can }: Props) {
    const base = `/transfers/${doc.id}`;
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [receiveOpen, setReceiveOpen] = useState(false);
    const [key, setKey] = useState("");
    const [qty, setQty] = useState<Record<number, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const inTransitItems = doc.items.filter((i) => Number(i.remaining) > 0);
    const showFlow =
        (doc.status !== "draft" &&
            doc.status !== "pending_approval" &&
            doc.status !== "approved" &&
            doc.status !== "cancelled") ||
        Number(doc.items[0]?.qty_sent) > 0;

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

    function openReceive() {
        setKey(newKey());
        setQty({});
        setErrors({});
        setReceiveOpen(true);
    }

    function fillAll() {
        setQty(
            Object.fromEntries(
                inTransitItems.map((i) => [i.id, String(Number(i.remaining))])
            )
        );
    }

    function submitReceive() {
        const lines = Object.entries(qty)
            .filter(([, v]) => v !== "" && Number(v) > 0)
            .map(([id, v]) => ({ item_id: Number(id), quantity: v }));

        setBusy(true);
        setErrors({});

        router.post(
            `${base}/receive`,
            { idempotency_key: key, lines },
            {
                preserveScroll: true,
                onSuccess: () => setReceiveOpen(false),
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setBusy(false),
            }
        );
    }

    const errorText = Object.values(errors)[0];

    return (
        <>
            <Head title={doc.number} />

            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-3">
                        <h1 className="font-mono text-xl font-semibold">
                            {doc.number}
                        </h1>
                        <TransferStatusBadge status={doc.status} />
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
                                            "Draft akan dihapus permanen. Stok tidak terpengaruh.",
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
                                            "Setelah diajukan, item dan gudang tidak dapat diubah. Stok belum berubah sampai transfer dikirim.",
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
                                        description:
                                            "Transfer yang disetujui boleh dikirim oleh gudang sumber.",
                                        label: "Ya, setujui",
                                        url: `${base}/approve`,
                                        method: "post",
                                    })
                                }
                            >
                                <Check className="mr-1 size-4" /> Setujui
                            </Button>
                        )}
                        {can.dispatch && (
                            <Button
                                onClick={() =>
                                    setConfirm({
                                        title: `Kirim ${doc.number}?`,
                                        description: `Stok di gudang ${doc.source.code} akan langsung berkurang dan barang berstatus dalam perjalanan. Ditolak jika stok tidak cukup.`,
                                        label: "Ya, kirim",
                                        url: `${base}/dispatch`,
                                        method: "post",
                                    })
                                }
                            >
                                <Truck className="mr-1 size-4" /> Kirim
                            </Button>
                        )}
                        {can.receive && (
                            <Button onClick={openReceive}>
                                <PackageCheck className="mr-1 size-4" /> Terima
                                barang
                            </Button>
                        )}
                        {can.returnToSource && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setConfirm({
                                        title: "Retur sisa ke gudang sumber?",
                                        description: `Semua barang yang belum diterima dikembalikan ke ${doc.source.code} dan transfer ditutup. Tindakan ini tidak dapat dibatalkan.`,
                                        label: "Ya, retur sisa",
                                        url: `${base}/return`,
                                        method: "post",
                                        destructive: true,
                                    })
                                }
                            >
                                <Undo2 className="mr-1 size-4" /> Retur sisa
                            </Button>
                        )}
                        {can.cancel && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setConfirm({
                                        title: `Batalkan ${doc.number}?`,
                                        description:
                                            "Transfer dibatalkan sebelum dikirim. Stok tidak terpengaruh.",
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

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Field label="Rute">
                        <span className="inline-flex items-center gap-2">
                            {doc.source.code} - {doc.source.name}
                            <ArrowRight className="size-4 text-muted-foreground" />
                            {doc.destination.code} - {doc.destination.name}
                        </span>
                    </Field>
                    <Field label="Referensi">{doc.reference ?? "-"}</Field>
                    <Field label="Catatan">{doc.notes ?? "-"}</Field>
                    <Field label="Dibuat">
                        {formatDateTime(doc.created_at)} oleh {doc.creator.name}
                    </Field>
                    <Field label="Diajukan">
                        {formatDateTime(doc.submitted_at)}
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
                    <Field label="Dikirim">
                        {doc.dispatched_at
                            ? `${formatDateTime(doc.dispatched_at)} oleh ${
                                  doc.dispatcher?.name ?? "-"
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
                                    Rencana
                                </TableHead>
                                {showFlow && (
                                    <>
                                        <TableHead className="text-right">
                                            Dikirim
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Diterima
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Diretur
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Dalam perjalanan
                                        </TableHead>
                                    </>
                                )}
                                <TableHead>Satuan</TableHead>
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
                                    {showFlow && (
                                        <>
                                            <TableCell className="text-right">
                                                {formatQty(i.qty_sent)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatQty(i.qty_received)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatQty(i.qty_returned)}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatQty(i.remaining)}
                                            </TableCell>
                                        </>
                                    )}
                                    <TableCell>
                                        {i.product.unit?.symbol ?? "-"}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div>
                    <Button variant="ghost" asChild>
                        <Link href="/transfers">Kembali ke daftar</Link>
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

            <Dialog open={receiveOpen} onOpenChange={setReceiveOpen}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Terima barang {doc.number}</DialogTitle>
                        <DialogDescription>
                            Isi jumlah yang benar-benar diterima di{" "}
                            {doc.destination.code}. Boleh sebagian, sisanya
                            tetap dalam perjalanan. Kosongkan baris yang belum
                            diterima.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="flex justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={fillAll}
                            >
                                Isi semua sisa
                            </Button>
                        </div>

                        {inTransitItems.map((i) => (
                            <div key={i.id} className="flex items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {i.product.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {i.product.sku} · sisa{" "}
                                        {formatQty(i.remaining)}{" "}
                                        {i.product.unit?.symbol}
                                    </p>
                                </div>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.001"
                                    inputMode="decimal"
                                    className="w-32"
                                    aria-label={`Jumlah diterima ${i.product.name}`}
                                    value={qty[i.id] ?? ""}
                                    onChange={(e) =>
                                        setQty({
                                            ...qty,
                                            [i.id]: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        ))}

                        {errorText && (
                            <p className="text-sm text-destructive">
                                {errorText}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setReceiveOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button onClick={submitReceive} disabled={busy}>
                            {busy ? "Menyimpan..." : "Catat penerimaan"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

TransfersShow.layout = {
    breadcrumbs: [
        { title: "Transfer", href: "/transfers" },
        { title: "Detail", href: "#" },
    ],
};
