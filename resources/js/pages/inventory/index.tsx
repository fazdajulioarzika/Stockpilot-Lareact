import { Head, router } from "@inertiajs/react";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
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

type Stock = {
    id: number;
    quantity: string;
    warehouse: { code: string; name: string };
    product: {
        sku: string;
        name: string;
        min_stock: string;
        unit: { symbol: string } | null;
    };
};

type Paginated<T> = {
    data: T[];
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

type Filters = {
    search: string;
    warehouse_id: string;
    category_id: string;
    low: boolean;
};

type Props = {
    stocks: Paginated<Stock>;
    warehouses: { id: number; code: string; name: string }[];
    categories: { id: number; name: string }[];
    filters: Filters;
};

const fmt = (v: string | number) =>
    Number(v).toLocaleString("id-ID", { maximumFractionDigits: 3 });

export default function InventoryIndex({
    stocks,
    warehouses,
    categories,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, search, ...next };
        router.get(
            "/inventory",
            {
                search: m.search || undefined,
                warehouse_id:
                    m.warehouse_id !== "all" ? m.warehouse_id : undefined,
                category_id:
                    m.category_id !== "all" ? m.category_id : undefined,
                low: m.low ? 1 : undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    return (
        <>
            <Head title="Inventory" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
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
                        value={filters.warehouse_id}
                        onValueChange={(v) => applyFilters({ warehouse_id: v })}
                    >
                        <SelectTrigger className="w-48">
                            <SelectValue placeholder="Gudang" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua gudang</SelectItem>
                            {warehouses.map((w) => (
                                <SelectItem key={w.id} value={String(w.id)}>
                                    {w.code} - {w.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.category_id}
                        onValueChange={(v) => applyFilters({ category_id: v })}
                    >
                        <SelectTrigger className="w-44">
                            <SelectValue placeholder="Kategori" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua kategori</SelectItem>
                            {categories.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="low"
                            checked={filters.low}
                            onCheckedChange={(c) =>
                                applyFilters({ low: c === true })
                            }
                        />
                        <Label htmlFor="low">Hanya stok rendah</Label>
                    </div>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Gudang</TableHead>
                                <TableHead>SKU</TableHead>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">
                                    Stok
                                </TableHead>
                                <TableHead>Satuan</TableHead>
                                <TableHead className="text-right">
                                    Minimum
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {stocks.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada data stok.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                stocks.data.map((s) => {
                                    const low =
                                        Number(s.quantity) <=
                                        Number(s.product.min_stock);

                                    return (
                                        <TableRow key={s.id}>
                                            <TableCell>
                                                {s.warehouse.code}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">
                                                {s.product.sku}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {s.product.name}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {fmt(s.quantity)}
                                            </TableCell>
                                            <TableCell>
                                                {s.product.unit?.symbol ?? "-"}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {fmt(s.product.min_stock)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        low
                                                            ? "destructive"
                                                            : "secondary"
                                                    }
                                                >
                                                    {low
                                                        ? "Stok rendah"
                                                        : "Aman"}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>
                </div>

                {stocks.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {stocks.total} baris
                        </span>
                        <div className="flex gap-1">
                            {stocks.links.map((link, i) => (
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
        </>
    );
}

InventoryIndex.layout = {
    breadcrumbs: [{ title: "Inventory", href: "/inventory" }],
};
