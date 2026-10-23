import { Head, router, useForm } from "@inertiajs/react";
import { Pencil, Plus, Power } from "lucide-react";
import { FormEvent, useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Supplier = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    address: string | null;
    is_active: boolean;
};

type Paginated<T> = {
    data: T[];
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

type Filters = { search: string; status: string };

type Props = { suppliers: Paginated<Supplier>; filters: Filters };

const emptyForm = {
    name: "",
    email: "",
    phone: "",
    address: "",
    is_active: true,
};

export default function SuppliersIndex({ suppliers, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Supplier | null>(null);

    const form = useForm(emptyForm);

    function applyFilters(next: Partial<Filters>) {
        const merged = { ...filters, search, ...next };
        router.get(
            "/suppliers",
            {
                search: merged.search || undefined,
                status: merged.status !== "all" ? merged.status : undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    function openCreate() {
        setEditing(null);
        form.setData({ ...emptyForm });
        form.clearErrors();
        setFormOpen(true);
    }

    function openEdit(s: Supplier) {
        setEditing(s);
        form.setData({
            name: s.name,
            email: s.email ?? "",
            phone: s.phone ?? "",
            address: s.address ?? "",
            is_active: s.is_active,
        });
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
            form.put(`/suppliers/${editing.id}`, options);
        } else {
            form.post("/suppliers", options);
        }
    }

    function toggleActive(s: Supplier) {
        router.patch(
            `/suppliers/${s.id}/toggle-active`,
            {},
            { preserveScroll: true }
        );
    }

    return (
        <>
            <Head title="Suppliers" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap gap-2">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                applyFilters({ search });
                            }}
                            className="flex gap-2"
                        >
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari nama, email, telepon..."
                                className="w-64"
                            />
                            <Button type="submit" variant="secondary">
                                Cari
                            </Button>
                        </form>
                        <Select
                            value={filters.status}
                            onValueChange={(v) => applyFilters({ status: v })}
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Semua status
                                </SelectItem>
                                <SelectItem value="active">Aktif</SelectItem>
                                <SelectItem value="inactive">
                                    Nonaktif
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button onClick={openCreate}>
                        <Plus className="mr-1 size-4" /> Tambah Supplier
                    </Button>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nama</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Telepon</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-28 text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {suppliers.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada supplier.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                suppliers.data.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell className="font-medium">
                                            {s.name}
                                        </TableCell>
                                        <TableCell>{s.email ?? "-"}</TableCell>
                                        <TableCell>{s.phone ?? "-"}</TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    s.is_active
                                                        ? "default"
                                                        : "secondary"
                                                }
                                            >
                                                {s.is_active
                                                    ? "Aktif"
                                                    : "Nonaktif"}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ubah ${s.name}`}
                                                onClick={() => openEdit(s)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={
                                                    s.is_active
                                                        ? `Nonaktifkan ${s.name}`
                                                        : `Aktifkan ${s.name}`
                                                }
                                                onClick={() => toggleActive(s)}
                                            >
                                                <Power className="size-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {suppliers.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {suppliers.total} supplier
                        </span>
                        <div className="flex gap-1">
                            {suppliers.links.map((link, i) => (
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
                                {editing ? "Ubah Supplier" : "Tambah Supplier"}
                            </DialogTitle>
                            <DialogDescription>
                                Email dan telepon boleh dikosongkan.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="my-4 grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama supplier</Label>
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
                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) =>
                                            form.setData(
                                                "email",
                                                e.target.value
                                            )
                                        }
                                    />
                                    {form.errors.email && (
                                        <p className="text-sm text-destructive">
                                            {form.errors.email}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="phone">Telepon</Label>
                                    <Input
                                        id="phone"
                                        value={form.data.phone}
                                        onChange={(e) =>
                                            form.setData(
                                                "phone",
                                                e.target.value
                                            )
                                        }
                                    />
                                    {form.errors.phone && (
                                        <p className="text-sm text-destructive">
                                            {form.errors.phone}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="address">Alamat</Label>
                                <textarea
                                    id="address"
                                    rows={3}
                                    value={form.data.address}
                                    onChange={(e) =>
                                        form.setData("address", e.target.value)
                                    }
                                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                {form.errors.address && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.address}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="is_active"
                                    checked={form.data.is_active}
                                    onCheckedChange={(c) =>
                                        form.setData("is_active", c === true)
                                    }
                                />
                                <Label htmlFor="is_active">
                                    Supplier aktif
                                </Label>
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
        </>
    );
}

SuppliersIndex.layout = {
    breadcrumbs: [{ title: "Suppliers", href: "/suppliers" }],
};
