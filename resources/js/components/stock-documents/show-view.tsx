import { Head, Link, router } from "@inertiajs/react";
import { Pencil, Send, Trash2 } from "lucide-react";
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

import { StatusBadge } from "./status-badge";
import { DocumentMeta, formatDateTime, formatQty } from "./types";

type ShowDocument = {
    id: number;
    number: string;
    status: string;
    reference: string | null;
    notes: string | null;
    reason?: string | null;
    posted_at: string | null;
    created_at: string;
    warehouse: { code: string; name: string };
    creator: { name: string };
    poster: { name: string } | null;
    supplier?: { name: string } | null;
    items: {
        id: number;
        quantity: string;
        product: { sku: string; name: string; unit: { symbol: string } | null };
    }[];
};

export type ShowProps = {
    meta: DocumentMeta;
    document: ShowDocument;
    can: { update: boolean; post: boolean };
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

export default function ShowView({ meta, document: doc, can }: ShowProps) {
    const [postOpen, setPostOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    function confirmPost() {
        setBusy(true);
        router.post(
            `${meta.basePath}/${doc.id}/post`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setPostOpen(false);
                },
            }
        );
    }

    function confirmDelete() {
        setBusy(true);
        router.delete(`${meta.basePath}/${doc.id}`, {
            onFinish: () => {
                setBusy(false);
                setDeleteOpen(false);
            },
        });
    }

    const effect =
        meta.type === "receipt"
            ? `menambah stok di gudang ${doc.warehouse.code}`
            : `mengurangi stok di gudang ${doc.warehouse.code} (ditolak jika stok tidak cukup)`;

    return (
        <>
            <Head title={doc.number} />

            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-3">
                        <h1 className="font-mono text-xl font-semibold">
                            {doc.number}
                        </h1>
                        <StatusBadge status={doc.status} />
                    </div>

                    <div className="flex gap-2">
                        {can.update && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`${meta.basePath}/${doc.id}/edit`}
                                    >
                                        <Pencil className="mr-1 size-4" /> Ubah
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setDeleteOpen(true)}
                                >
                                    <Trash2 className="mr-1 size-4" /> Hapus
                                    draft
                                </Button>
                            </>
                        )}
                        {can.post && (
                            <Button onClick={() => setPostOpen(true)}>
                                <Send className="mr-1 size-4" /> Posting
                            </Button>
                        )}
                    </div>
                </div>

                <dl className="grid gap-4 sm:grid-cols-2">
                    <Field label="Gudang">
                        {doc.warehouse.code} - {doc.warehouse.name}
                    </Field>
                    {meta.type === "receipt" ? (
                        <Field label="Supplier">
                            {doc.supplier?.name ?? "-"}
                        </Field>
                    ) : (
                        <Field label="Alasan">{doc.reason ?? "-"}</Field>
                    )}
                    <Field label="Referensi">{doc.reference ?? "-"}</Field>
                    <Field label="Catatan">{doc.notes ?? "-"}</Field>
                    <Field label="Dibuat">
                        {formatDateTime(doc.created_at)} oleh {doc.creator.name}
                    </Field>
                    <Field label="Diposting">
                        {doc.posted_at
                            ? `${formatDateTime(doc.posted_at)} oleh ${
                                  doc.poster?.name ?? "-"
                              }`
                            : "-"}
                    </Field>
                </dl>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>SKU</TableHead>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">
                                    Jumlah
                                </TableHead>
                                <TableHead>Satuan</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {doc.items.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="font-mono text-xs">
                                        {item.product.sku}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {item.product.name}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatQty(item.quantity)}
                                    </TableCell>
                                    <TableCell>
                                        {item.product.unit?.symbol ?? "-"}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div>
                    <Button variant="ghost" asChild>
                        <Link href={meta.basePath}>Kembali ke daftar</Link>
                    </Button>
                </div>
            </div>

            <Dialog open={postOpen} onOpenChange={setPostOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Posting {doc.number}?</DialogTitle>
                        <DialogDescription>
                            Posting akan {effect}. Setelah diposting, dokumen
                            tidak dapat diubah atau dihapus.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPostOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button onClick={confirmPost} disabled={busy}>
                            {busy ? "Memposting..." : "Ya, posting"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus draft {doc.number}?</DialogTitle>
                        <DialogDescription>
                            Draft akan dihapus permanen. Stok tidak terpengaruh.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleteOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmDelete}
                            disabled={busy}
                        >
                            {busy ? "Menghapus..." : "Hapus"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
