import { Head, Link, router } from "@inertiajs/react";
import {
    Ban,
    Check,
    ClipboardCheck,
    Pencil,
    Play,
    Save,
    Send,
    Trash2,
    Undo2,
} from "lucide-react";
import { useEffect, useState } from "react";

import { formatDateTime, formatQty } from "@/components/stock-documents/types";
import { StocktakeStatusBadge } from "@/components/stocktakes/status-badge";
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
    system_qty: string | null;
    counted_qty: string | null;
    difference: string | null;
    reason: string | null;
    product: { sku: string; name: string; unit: { symbol: string } | null };
};

type Doc = {
    id: number;
    number: string;
    status: string;
    notes: string | null;
    rejection_note: string | null;
    started_at: string | null;
    submitted_at: string | null;
    posted_at: string | null;
    completed_at: string | null;
    created_at: string;
    warehouse: { code: string; name: string };
    creator: { name: string };
    starter: { name: string } | null;
    submitter: { name: string } | null;
    approver: { name: string } | null;
    items: Item[];
};

type Can = {
    update: boolean;
    delete: boolean;
    start: boolean;
    count: boolean;
    submit: boolean;
    approve: boolean;
    reject: boolean;
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

type Row = { counted: string; reason: string };

const serverCounted = (i: Item) =>
    i.counted_qty === null ? "" : String(Number(i.counted_qty));

const toRows = (items: Item[]): Record<number, Row> =>
    Object.fromEntries(
        items.map((i) => [
            i.id,
            { counted: serverCounted(i), reason: i.reason ?? "" },
        ])
    );

function sameCount(a: string, b: string) {
    return a === b || (a !== "" && b !== "" && Number(a) === Number(b));
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

function Diff({ value }: { value: number | null }) {
    if (value === null) return <span className="text-muted-foreground">-</span>;

    const cls =
        value < 0
            ? "text-destructive"
            : value > 0
            ? "text-green-600 dark:text-green-400"
            : "";

    return (
        <span className={`font-medium ${cls}`}>
            {value > 0 ? "+" : ""}
            {formatQty(value)}
        </span>
    );
}

export default function StocktakesShow({ document: doc, can }: Props) {
    const base = `/stocktakes/${doc.id}`;
    const [rows, setRows] = useState<Record<number, Row>>(() =>
        toRows(doc.items)
    );
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [rejectOpen, setRejectOpen] = useState(false);
    const [note, setNote] = useState("");
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    // Sinkronkan input dengan data server setelah simpan atau muat ulang.
    useEffect(() => {
        setRows(toRows(doc.items));
    }, [doc.items]);

    const editing = can.count;
    const showCounts = doc.status !== "draft";

    const dirty = doc.items.some((i) => {
        const r = rows[i.id];

        return (
            !!r &&
            (!sameCount(r.counted, serverCounted(i)) ||
                r.reason !== (i.reason ?? ""))
        );
    });

    const uncounted = doc.items.filter((i) =>
        editing ? rows[i.id]?.counted === "" : i.counted_qty === null
    ).length;

    function liveDiff(i: Item): number | null {
        if (!editing)
            return i.difference === null ? null : Number(i.difference);

        const counted = rows[i.id]?.counted ?? "";

        return counted === ""
            ? null
            : Number(counted) - Number(i.system_qty ?? 0);
    }

    function setRow(id: number, patch: Partial<Row>) {
        setRows({ ...rows, [id]: { ...rows[id], ...patch } });
    }

    function saveCounts() {
        setBusy(true);
        setErrors({});

        router.post(
            `${base}/count`,
            {
                lines: doc.items.map((i) => ({
                    item_id: i.id,
                    counted_qty: rows[i.id]?.counted ?? "",
                    reason: rows[i.id]?.reason ?? "",
                })),
            },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setBusy(false),
            }
        );
    }

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

    function submitReject() {
        setBusy(true);
        setErrors({});

        router.post(
            `${base}/reject`,
            { note },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejectOpen(false);
                    setNote("");
                },
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setBusy(false),
            }
        );
    }

    const errorText = Object.values(errors)[0];
    const adjustments = doc.items.filter(
        (i) => i.difference !== null && Number(i.difference) !== 0
    ).length;

    return (
        <>
            <Head title={doc.number} />

            <div className="flex max-w-6xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-3">
                        <h1 className="font-mono text-xl font-semibold">
                            {doc.number}
                        </h1>
                        <StocktakeStatusBadge status={doc.status} />
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
                        {can.start && (
                            <Button
                                onClick={() =>
                                    setConfirm({
                                        title: `Mulai penghitungan ${doc.number}?`,
                                        description: `Gudang ${doc.warehouse.code} akan dikunci: Barang Masuk, Barang Keluar, kirim dan terima transfer di gudang ini ditolak sampai opname diposting atau dibatalkan. Saldo sistem difoto saat ini.`,
                                        label: "Ya, mulai hitung",
                                        url: `${base}/start`,
                                        method: "post",
                                    })
                                }
                            >
                                <Play className="mr-1 size-4" /> Mulai hitung
                            </Button>
                        )}
                        {can.count && (
                            <Button
                                variant="outline"
                                onClick={saveCounts}
                                disabled={busy || !dirty}
                            >
                                <Save className="mr-1 size-4" /> Simpan hasil
                                hitung
                            </Button>
                        )}
                        {can.submit && (
                            <Button
                                disabled={busy || dirty}
                                title={
                                    dirty
                                        ? "Simpan hasil hitung dulu"
                                        : undefined
                                }
                                onClick={() =>
                                    setConfirm({
                                        title: `Ajukan ${doc.number}?`,
                                        description:
                                            "Hasil hitung dikirim ke Manager untuk ditinjau. Gudang tetap terkunci sampai opname diposting atau dibatalkan.",
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
                                        title: `Setujui dan posting ${doc.number}?`,
                                        description: `${adjustments} item memiliki selisih dan akan diposting sebagai penyesuaian stok di ${doc.warehouse.code}. Setelah diposting, opname tidak dapat diubah dan gudang dibuka kembali.`,
                                        label: "Ya, setujui dan posting",
                                        url: `${base}/approve`,
                                        method: "post",
                                    })
                                }
                            >
                                <Check className="mr-1 size-4" /> Setujui
                            </Button>
                        )}
                        {can.reject && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setNote("");
                                    setErrors({});
                                    setRejectOpen(true);
                                }}
                            >
                                <Undo2 className="mr-1 size-4" /> Tolak
                            </Button>
                        )}
                        {can.cancel && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setConfirm({
                                        title: `Batalkan ${doc.number}?`,
                                        description:
                                            "Opname dibatalkan dan gudang dibuka kembali. Saldo stok tidak berubah, hasil hitung tidak dipakai.",
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

                {doc.rejection_note && doc.status === "counting" && (
                    <div className="flex items-start gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                        <ClipboardCheck className="mt-0.5 size-4 shrink-0" />
                        <p>
                            <span className="font-medium">
                                Dikembalikan oleh Manager:
                            </span>{" "}
                            {doc.rejection_note}
                        </p>
                    </div>
                )}

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Field label="Gudang">
                        {doc.warehouse.code} - {doc.warehouse.name}
                    </Field>
                    <Field label="Catatan">{doc.notes ?? "-"}</Field>
                    <Field label="Dibuat">
                        {formatDateTime(doc.created_at)} oleh {doc.creator.name}
                    </Field>
                    <Field label="Mulai hitung">
                        {doc.started_at
                            ? `${formatDateTime(doc.started_at)} oleh ${
                                  doc.starter?.name ?? "-"
                              }`
                            : "-"}
                    </Field>
                    <Field label="Diajukan">
                        {doc.submitted_at
                            ? `${formatDateTime(doc.submitted_at)} oleh ${
                                  doc.submitter?.name ?? "-"
                              }`
                            : "-"}
                    </Field>
                    <Field label="Diposting">
                        {doc.posted_at
                            ? `${formatDateTime(doc.posted_at)} oleh ${
                                  doc.approver?.name ?? "-"
                              }`
                            : "-"}
                    </Field>
                </dl>

                {doc.status === "draft" && (
                    <p className="text-sm text-muted-foreground">
                        Draft belum mengunci gudang. Saat penghitungan dimulai,
                        semua produk bersaldo masuk otomatis ke daftar dan saldo
                        sistem difoto. Produk tambahan di draft:{" "}
                        {doc.items.length === 0
                            ? "tidak ada"
                            : doc.items.map((i) => i.product.sku).join(", ")}
                        .
                    </p>
                )}

                {showCounts && (
                    <>
                        {editing && (
                            <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                                <span>
                                    {uncounted > 0
                                        ? `${uncounted} item belum dihitung.`
                                        : "Semua item sudah dihitung."}
                                </span>
                                {dirty && (
                                    <span className="text-amber-600">
                                        Ada perubahan yang belum disimpan.
                                    </span>
                                )}
                            </div>
                        )}

                        {errorText && (
                            <p className="text-sm text-destructive">
                                {errorText}
                            </p>
                        )}

                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>SKU</TableHead>
                                        <TableHead>Produk</TableHead>
                                        <TableHead className="text-right">
                                            Sistem
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Hitung fisik
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Selisih
                                        </TableHead>
                                        <TableHead>Alasan</TableHead>
                                        <TableHead>Satuan</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {doc.items.map((i) => {
                                        const diff = liveDiff(i);

                                        return (
                                            <TableRow key={i.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {i.product.sku}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {i.product.name}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {i.system_qty === null
                                                        ? "-"
                                                        : formatQty(
                                                              i.system_qty
                                                          )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {editing ? (
                                                        <Input
                                                            type="number"
                                                            min="0"
                                                            step="0.001"
                                                            inputMode="decimal"
                                                            className="ml-auto w-28 text-right"
                                                            aria-label={`Hitung fisik ${i.product.name}`}
                                                            value={
                                                                rows[i.id]
                                                                    ?.counted ??
                                                                ""
                                                            }
                                                            onChange={(e) =>
                                                                setRow(i.id, {
                                                                    counted:
                                                                        e.target
                                                                            .value,
                                                                })
                                                            }
                                                        />
                                                    ) : i.counted_qty ===
                                                      null ? (
                                                        "-"
                                                    ) : (
                                                        formatQty(i.counted_qty)
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Diff value={diff} />
                                                </TableCell>
                                                <TableCell className="min-w-48 whitespace-normal">
                                                    {editing ? (
                                                        <Input
                                                            maxLength={255}
                                                            placeholder={
                                                                diff !== null &&
                                                                diff !== 0
                                                                    ? "Wajib diisi"
                                                                    : "Opsional"
                                                            }
                                                            aria-label={`Alasan ${i.product.name}`}
                                                            value={
                                                                rows[i.id]
                                                                    ?.reason ??
                                                                ""
                                                            }
                                                            onChange={(e) =>
                                                                setRow(i.id, {
                                                                    reason: e
                                                                        .target
                                                                        .value,
                                                                })
                                                            }
                                                        />
                                                    ) : (
                                                        i.reason ?? "-"
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {i.product.unit?.symbol ??
                                                        "-"}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}

                <div>
                    <Button variant="ghost" asChild>
                        <Link href="/stocktakes">Kembali ke daftar</Link>
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

            <Dialog open={rejectOpen} onOpenChange={setRejectOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Tolak {doc.number}?</DialogTitle>
                        <DialogDescription>
                            Opname dikembalikan ke status sedang dihitung. Hasil
                            hitung tidak hilang dan gudang tetap terkunci.
                            Catatan wajib diisi agar petugas tahu apa yang perlu
                            dihitung ulang.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="note">Catatan penolakan</Label>
                        <textarea
                            id="note"
                            rows={3}
                            maxLength={1000}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        {errors.note && (
                            <p className="text-sm text-destructive">
                                {errors.note}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setRejectOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submitReject}
                            disabled={busy || note.trim() === ""}
                        >
                            {busy ? "Memproses..." : "Tolak"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

StocktakesShow.layout = {
    breadcrumbs: [
        { title: "Stock Opname", href: "/stocktakes" },
        { title: "Detail", href: "#" },
    ],
};
