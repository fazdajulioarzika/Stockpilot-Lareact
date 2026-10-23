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

type Product = {
    id: number;
    sku: string;
    name: string;
    min_stock: string;
    is_active: boolean;
    category_id: number;
    unit_id: number;
    category: { id: number; name: string };
    unit: { id: number; name: string; symbol: string };
};

type Option = { id: number; name: string; symbol?: string };

type Paginated<T> = {
    data: T[];
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

type Filters = { search: string; status: string; category_id: string };

type Props = {
    products: Paginated<Product>;
    categories: Option[];
    units: Option[];
    filters: Filters;
};
const emptyForm = {
    sku: "",
    name: "",
    category_id: "",
    unit_id: "",
    min_stock: "0",
    is_active: true,
};
export default function ProductsIndex({
    products,
    categories,
    units,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Product | null>(null);

    const form = useForm(emptyForm);

    function applyFilters(next: Partial<Filters>) {
        const merged = { ...filters, search, ...next };
        router.get(
            "/products",
            {
                search: merged.search || undefined,
                status: merged.status !== "all" ? merged.status : undefined,
                category_id:
                    merged.category_id !== "all"
                        ? merged.category_id
                        : undefined,
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

    function openEdit(product: Product) {
        setEditing(product);
        form.setData({
            sku: product.sku,
            name: product.name,
            category_id: String(product.category_id),
            unit_id: String(product.unit_id),
            min_stock: String(Number(product.min_stock)),
            is_active: product.is_active,
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
            form.put(`/products/${editing.id}`, options);
        } else {
            form.post("/products", options);
        }
    }

    function toggleActive(product: Product) {
        router.patch(
            `/products/${product.id}/toggle-active`,
            {},
            { preserveScroll: true }
        );
    }

    return (
        <>
            <Head title="Products" />

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
                                placeholder="Cari SKU atau nama..."
                                className="w-64"
                            />
                            <Button type="submit" variant="secondary">
                                Cari
                            </Button>
                        </form>

                        <Select
                            value={filters.category_id}
                            onValueChange={(v) =>
                                applyFilters({ category_id: v })
                            }
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue placeholder="Kategori" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Semua kategori
                                </SelectItem>
                                {categories.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

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
                        <Plus className="mr-1 size-4" /> Tambah Produk
                    </Button>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>SKU</TableHead>
                                <TableHead>Nama</TableHead>
                                <TableHead>Kategori</TableHead>
                                <TableHead>Satuan</TableHead>
                                <TableHead className="text-right">
                                    Stok minimum
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-28 text-right">
                                    Aksi
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada produk.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                products.data.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell className="font-mono text-xs">
                                            {p.sku}
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {p.name}
                                        </TableCell>
                                        <TableCell>{p.category.name}</TableCell>
                                        <TableCell>{p.unit.symbol}</TableCell>
                                        <TableCell className="text-right">
                                            {Number(p.min_stock).toLocaleString(
                                                "id-ID"
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    p.is_active
                                                        ? "default"
                                                        : "secondary"
                                                }
                                            >
                                                {p.is_active
                                                    ? "Aktif"
                                                    : "Nonaktif"}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Ubah ${p.name}`}
                                                onClick={() => openEdit(p)}
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={
                                                    p.is_active
                                                        ? `Nonaktifkan ${p.name}`
                                                        : `Aktifkan ${p.name}`
                                                }
                                                onClick={() => toggleActive(p)}
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

                {products.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {products.total} produk
                        </span>
                        <div className="flex gap-1">
                            {products.links.map((link, i) => (
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
                                {editing ? "Ubah Produk" : "Tambah Produk"}
                            </DialogTitle>
                            <DialogDescription>
                                SKU harus unik.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="my-4 grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="sku">SKU</Label>
                                <Input
                                    id="sku"
                                    value={form.data.sku}
                                    onChange={(e) =>
                                        form.setData("sku", e.target.value)
                                    }
                                    autoFocus
                                />
                                {form.errors.sku && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.sku}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama produk</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData("name", e.target.value)
                                    }
                                />
                                {form.errors.name && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label>Kategori</Label>
                                    <Select
                                        value={form.data.category_id}
                                        onValueChange={(v) =>
                                            form.setData("category_id", v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih kategori" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {categories.map((c) => (
                                                <SelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.category_id && (
                                        <p className="text-sm text-destructive">
                                            {form.errors.category_id}
                                        </p>
                                    )}
                                </div>

                                <div className="grid gap-2">
                                    <Label>Satuan</Label>
                                    <Select
                                        value={form.data.unit_id}
                                        onValueChange={(v) =>
                                            form.setData("unit_id", v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Pilih satuan" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {units.map((u) => (
                                                <SelectItem
                                                    key={u.id}
                                                    value={String(u.id)}
                                                >
                                                    {u.name} ({u.symbol})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.unit_id && (
                                        <p className="text-sm text-destructive">
                                            {form.errors.unit_id}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="min_stock">Stok minimum</Label>
                                <Input
                                    id="min_stock"
                                    type="number"
                                    min="0"
                                    step="0.001"
                                    value={form.data.min_stock}
                                    onChange={(e) =>
                                        form.setData(
                                            "min_stock",
                                            e.target.value
                                        )
                                    }
                                />
                                {form.errors.min_stock && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.min_stock}
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
                                <Label htmlFor="is_active">Produk aktif</Label>
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

ProductsIndex.layout = {
    breadcrumbs: [{ title: "Products", href: "/products" }],
};
