import { Head, router } from "@inertiajs/react";
import { AlertTriangle } from "lucide-react";
import { useState } from "react";
import { ExportButtons } from "@/components/export-buttons";
import { Paginated } from "@/components/stock-documents/types";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
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

type Row = {
    id: number;
    warehouse: { code: string };
    product: { sku: string; name: string; unit: { symbol: string } | null };
    quantity: string;
    avg_cost: string;
    total_value: string;
    no_cost: boolean;
};

type Filters = { search: string; warehouse_id: string; category_id: string };

type Props = {
    stocks: Paginated<Row>;
    totals: { value: string; rows: number; missing_cost: number };
    byWarehouse: { code: string; value: string }[];
    warehouses: { id: number; code: string; name: string }[];
    categories: { id: number; name: string }[];
    filters: Filters;
};

const money = (v: string | number) =>
    Number(v).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });

const qty = (v: string | number) =>
    Number(v).toLocaleString("id-ID", { maximumFractionDigits: 3 });

export default function ValuationIndex({
    stocks,
    totals,
    byWarehouse,
    warehouses,
    categories,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, search, ...next };
        router.get(
            "/valuation",
            {
                search: m.search || undefined,
                warehouse_id:
                    m.warehouse_id !== "all" ? m.warehouse_id : undefined,
                category_id:
                    m.category_id !== "all" ? m.category_id : undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    return (
        <>
            <Head title="Nilai Persediaan" />

            <div className="flex flex-col gap-4 p-4">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card className="xl:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total nilai persediaan
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-3xl font-semibold">
                                {money(totals.value)}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {totals.rows.toLocaleString("id-ID")} baris
                                stok, metode rata-rata tertimbang per produk dan
                                gudang.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Per gudang
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-1 text-sm">
                            {byWarehouse.length === 0 && (
                                <span className="text-muted-foreground">-</span>
                            )}
                            {byWarehouse.map((w) => (
                                <div
                                    key={w.code}
                                    className="flex justify-between gap-2"
                                >
                                    <span>{w.code}</span>
                                    <span className="font-medium">
                                        {money(w.value)}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Tanpa biaya
                            </CardTitle>
                            <AlertTriangle
                                className={`size-4 ${
                                    totals.missing_cost > 0
                                        ? "text-destructive"
                                        : "text-muted-foreground"
                                }`}
                            />
                        </CardHeader>
                        <CardContent>
                            <p
                                className={`text-2xl font-semibold ${
                                    totals.missing_cost > 0
                                        ? "text-destructive"
                                        : ""
                                }`}
                            >
                                {totals.missing_cost.toLocaleString("id-ID")}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Baris stok bernilai nol. Nilai total di atas
                                lebih rendah dari sebenarnya sampai biaya diisi.
                            </p>
                        </CardContent>
                    </Card>
                </div>

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
                    <div className="ml-auto">
                        <ExportButtons
                            path="/exports/valuation"
                            params={{
                                search: filters.search,
                                warehouse_id: filters.warehouse_id,
                                category_id: filters.category_id,
                            }}
                        />
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
                                    Biaya rata-rata
                                </TableHead>
                                <TableHead className="text-right">
                                    Nilai
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {stocks.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Belum ada stok.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                stocks.data.map((s) => (
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
                                            {qty(s.quantity)}
                                        </TableCell>
                                        <TableCell>
                                            {s.product.unit?.symbol ?? "-"}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {s.no_cost ? (
                                                <Badge variant="destructive">
                                                    Tanpa biaya
                                                </Badge>
                                            ) : (
                                                money(s.avg_cost)
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {money(s.total_value)}
                                        </TableCell>
                                    </TableRow>
                                ))
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

ValuationIndex.layout = {
    breadcrumbs: [{ title: "Nilai Persediaan", href: "/valuation" }],
};
