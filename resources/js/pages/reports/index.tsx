import { Head, router } from "@inertiajs/react";
import { useState } from "react";

import { ExportButtons } from "@/components/export-buttons";
import { Paginated } from "@/components/stock-documents/types";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
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

type Row = {
    warehouse: { code: string };
    product: { sku: string; name: string; unit: { symbol: string } | null };
    opening: string;
    inflow: string;
    outflow: string;
    closing: string;
    opening_value?: string;
    closing_value?: string;
};

type Filters = {
    from: string;
    to: string;
    warehouse_id: string;
    category_id: string;
    search: string;
};

type Props = {
    rows: Paginated<Row>;
    totals: {
        rows: number;
        inflow: string;
        outflow: string;
        opening_value?: string;
        closing_value?: string;
    };
    trend: {
        granularity: "day" | "month";
        points: { key: string; label: string; masuk: string; keluar: string }[];
    };
    filters: Filters;
    warehouses: { id: number; code: string; name: string }[];
    categories: { id: number; name: string }[];
    canValue: boolean;
    error: string | null;
};

const qty = (v: string | number) =>
    Number(v).toLocaleString("id-ID", { maximumFractionDigits: 3 });

const money = (v: string | number) =>
    Number(v).toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });

export default function ReportsIndex({
    rows,
    totals,
    trend,
    filters,
    warehouses,
    categories,
    canValue,
    error,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<Filters>) {
        const m = { ...filters, search, ...next };
        router.get(
            "/reports",
            {
                from: m.from || undefined,
                to: m.to || undefined,
                warehouse_id:
                    m.warehouse_id !== "all" ? m.warehouse_id : undefined,
                category_id:
                    m.category_id !== "all" ? m.category_id : undefined,
                search: m.search || undefined,
            },
            { preserveState: true, replace: true }
        );
    }

    const max = Math.max(
        1,
        ...trend.points.flatMap((p) => [Number(p.masuk), Number(p.keluar)])
    );
    const step = Math.max(1, Math.ceil(trend.points.length / 10));
    const net = Number(totals.inflow) - Number(totals.outflow);
    const exportParams = {
        from: filters.from,
        to: filters.to,
        warehouse_id: filters.warehouse_id,
        category_id: filters.category_id,
        search: filters.search,
    };

    return (
        <>
            <Head title="Laporan Mutasi" />

            <div className="flex flex-col gap-4 p-4">
                {error && (
                    <p
                        role="alert"
                        className="rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                    >
                        {error}
                    </p>
                )}

                <div className="flex flex-wrap items-end gap-2">
                    <div className="grid gap-1">
                        <Label
                            htmlFor="from"
                            className="text-xs text-muted-foreground"
                        >
                            Dari
                        </Label>
                        <Input
                            id="from"
                            type="date"
                            value={filters.from}
                            onChange={(e) =>
                                applyFilters({ from: e.target.value })
                            }
                            className="w-40"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label
                            htmlFor="to"
                            className="text-xs text-muted-foreground"
                        >
                            Sampai
                        </Label>
                        <Input
                            id="to"
                            type="date"
                            value={filters.to}
                            onChange={(e) =>
                                applyFilters({ to: e.target.value })
                            }
                            className="w-40"
                        />
                    </div>

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
                            className="w-56"
                        />
                        <Button type="submit" variant="secondary">
                            Cari
                        </Button>
                    </form>

                    <div className="ml-auto mt-4">
                        <ExportButtons
                            path="/reports/export"
                            params={exportParams}
                        />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total masuk
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">
                                {qty(totals.inflow)}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Semua tipe mutasi, lintas satuan
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">
                                Total keluar
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">
                                {qty(totals.outflow)}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Selisih {net > 0 ? "+" : ""}
                                {qty(net)}
                            </p>
                        </CardContent>
                    </Card>
                    {canValue &&
                        totals.opening_value !== undefined &&
                        totals.closing_value !== undefined && (
                            <>
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Nilai awal
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-2xl font-semibold">
                                            {money(totals.opening_value)}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Riwayat sebelum fitur biaya dipakai
                                            bernilai nol
                                        </p>
                                    </CardContent>
                                </Card>
                                <Card>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm font-medium text-muted-foreground">
                                            Nilai akhir
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-2xl font-semibold">
                                            {money(totals.closing_value)}
                                        </p>
                                    </CardContent>
                                </Card>
                            </>
                        )}
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">
                            Tren barang masuk dan keluar (
                            {trend.granularity === "day" ? "harian" : "bulanan"}
                            )
                        </CardTitle>
                        <div className="flex gap-4 text-xs text-muted-foreground">
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-sm bg-primary" />{" "}
                                Masuk
                            </span>
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-sm bg-destructive" />{" "}
                                Keluar
                            </span>
                            <span>
                                Hanya Barang Masuk dan Keluar, sudah dikurangi
                                pembalikan
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div
                            className="flex items-end gap-1"
                            role="img"
                            aria-label="Grafik tren barang masuk dan keluar"
                        >
                            {trend.points.map((p, i) => (
                                <div
                                    key={p.key}
                                    className="flex flex-1 flex-col items-center gap-1"
                                    title={`${p.label}: masuk ${qty(
                                        p.masuk
                                    )}, keluar ${qty(p.keluar)}`}
                                >
                                    <div className="flex h-32 w-full items-end justify-center gap-px">
                                        <div
                                            className="w-1/2 max-w-3 rounded-t bg-primary"
                                            style={{
                                                height: `${
                                                    (Number(p.masuk) / max) *
                                                    100
                                                }%`,
                                                minHeight:
                                                    Number(p.masuk) > 0 ? 2 : 0,
                                            }}
                                        />
                                        <div
                                            className="w-1/2 max-w-3 rounded-t bg-destructive"
                                            style={{
                                                height: `${
                                                    (Number(p.keluar) / max) *
                                                    100
                                                }%`,
                                                minHeight:
                                                    Number(p.keluar) > 0
                                                        ? 2
                                                        : 0,
                                            }}
                                        />
                                    </div>
                                    <span className="h-3 text-[10px] text-muted-foreground">
                                        {i % step === 0 ? p.label : ""}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Gudang</TableHead>
                                <TableHead>SKU</TableHead>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">
                                    Stok awal
                                </TableHead>
                                <TableHead className="text-right">
                                    Masuk
                                </TableHead>
                                <TableHead className="text-right">
                                    Keluar
                                </TableHead>
                                <TableHead className="text-right">
                                    Stok akhir
                                </TableHead>
                                <TableHead>Satuan</TableHead>
                                {canValue && (
                                    <>
                                        <TableHead className="text-right">
                                            Nilai awal
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Nilai akhir
                                        </TableHead>
                                    </>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.data.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={canValue ? 10 : 8}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        Tidak ada pergerakan atau saldo pada
                                        periode ini.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                rows.data.map((r, i) => (
                                    <TableRow
                                        key={`${r.warehouse.code}-${r.product.sku}-${i}`}
                                    >
                                        <TableCell>
                                            {r.warehouse.code}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {r.product.sku}
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {r.product.name}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {qty(r.opening)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {qty(r.inflow)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {qty(r.outflow)}
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {qty(r.closing)}
                                        </TableCell>
                                        <TableCell>
                                            {r.product.unit?.symbol ?? "-"}
                                        </TableCell>
                                        {canValue && (
                                            <>
                                                <TableCell className="text-right">
                                                    {money(
                                                        r.opening_value ?? 0
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {money(
                                                        r.closing_value ?? 0
                                                    )}
                                                </TableCell>
                                            </>
                                        )}
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {rows.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Total {rows.total} baris
                        </span>
                        <div className="flex gap-1">
                            {rows.links.map((link, i) => (
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

ReportsIndex.layout = {
    breadcrumbs: [{ title: "Laporan", href: "/reports" }],
};
