import { Head, router, useForm } from "@inertiajs/react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { FormEvent, useState } from "react";

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

type Unit = {
    id: number;
    name: string;
    symbol: string;
    products_count: number;
};

type Paginated<T> = {
    data: T[];
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

type Props = {
    units: Paginated<Unit>;
    filters: { search: string };
};

export default function UnitsIndex({ units, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Unit | null>(null);
    const [deleting, setDeleting] = useState<Unit | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);

    const form = useForm({ name: "", symbol: "" });

    function openCreate() {
        setEditing(null);
        form.setData({ name: "", symbol: "" });
        form.clearErrors();
        setFormOpen(true);
    }

    function openEdit(unit: Unit) {
        setEditing(unit);
        form.setData({ name: unit.name, symbol: unit.symbol });
        form.clearErrors();
        setFormOpen(true);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setFormOpen(false),
        };
        if (editing) {
            form.put(`/units/${editing.id}`, options);
        } else {
            form.post("/units", options);
        }
    }

    function applySearch(e: FormEvent) {
        e.preventDefault();
        router.get(
            "/units",
            { search: search || undefined },
            { preserveState: true, replace: true }
        );
    }

    function confirmDelete() {
        if (!deleting) return;
        setDeleteProcessing(true);
        router.delete(`/units/${deleting.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setDeleteProcessing(false);
                setDeleting(null);
            },
        });
    }

    return (
        <>
            <Head title="Units" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <form onSubmit={applySearch} className="flex gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari satuan..."
                            className="w-64"
                        />
                        <Button type="submit" variant="secondary">
                            Cari
                        </Button>
                    </form>
                    <Button onClick={openCreate}>
                        <Plus className="mr-1 size-4" /> Tambah Satuan
                    </Button>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Simbol</TableHead>
                                <TableHead className="text-right">
                                    Produk
                                </TableHead>
                                <TableHead className="w-28 text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {units.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada satuan.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                units.data.map((unit) => (
                                    <TableRow key={unit.id}>
                                        <TableCell className="font-medium">
                                            {unit.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {unit.symbol}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {unit.products_count}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ubah ${unit.name}`}
                                                onClick={() => openEdit(unit)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Hapus ${unit.name}`}
                                                onClick={() =>
                                                    setDeleting(unit)
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {units.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {units.total} satuan
                        </span>
                        <div className="flex gap-1">
                            {units.links.map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? "default" : "outline"
                                    }
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true }
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent>
                    <form onSubmit={submit}>
                        <DialogHeader>
                            <DialogTitle>
                                {editing ? "Ubah Satuan" : "Tambah Satuan"}
                            </DialogTitle>
                            <DialogDescription>
                                Nama dan simbol harus unik.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="my-4 grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama satuan</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData("name", e.target.value)
                                    }
                                    autoFocus
                                />
                                {form.errors.name && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.name}
                                    </p>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="symbol">Simbol</Label>
                                <Input
                                    id="symbol"
                                    value={form.data.symbol}
                                    onChange={(e) =>
                                        form.setData("symbol", e.target.value)
                                    }
                                    placeholder="pcs, kg, box"
                                />
                                {form.errors.symbol && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.symbol}
                                    </p>
                                )}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setFormOpen(false)}
                            >
                                Batal
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? "Menyimpan..." : "Simpan"}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={!!deleting}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus satuan?</DialogTitle>
                        <DialogDescription>
                            Satuan "{deleting?.name}" akan dihapus. Tindakan ini
                            tidak dapat dibatalkan.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(null)}
                        >
                            Batal
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={confirmDelete}
                            disabled={deleteProcessing}
                        >
                            {deleteProcessing ? "Menghapus..." : "Hapus"}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

UnitsIndex.layout = {
    breadcrumbs: [{ title: "Units", href: "/units" }],
};
