import { Head, Link } from "@inertiajs/react";
import {
    AlertTriangle,
    Boxes,
    Layers,
    Package,
    type LucideIcon,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

type Props = {
    scope: string;
    kpi: {
        total_sku: number;
        total_quantity: string;
        stock_rows: number;
        low_stock: number;
    };
    chart: { date: string; label: string; masuk: string; keluar: string }[];
    lowStock: {
        id: number;
        quantity: string;
        warehouse: { code: string };
        product: {
            sku: string;
            name: string;
            min_stock: string;
            unit: { symbol: string } | null;
        };
    }[];
    recent: {
        id: number;
        created_at: string;
        movement_type: string;
        quantity_delta: string;
        warehouse: { code: string };
        product: { sku: string; name: string };
        creator: { name: string };
    }[];
    attention: { label: string; count: number; href: string }[];
};

const fmt = (v: string | number) =>
    Number(v).toLocaleString("id-ID", { maximumFractionDigits: 3 });

const TYPE_LABEL: Record<string, string> = {
    receipt: "Barang masuk",
    issue: "Barang keluar",
    transfer_out: "Transfer keluar",
    transfer_in: "Transfer masuk",
    transfer_return: "Retur transfer",
    adjustment: "Penyesuaian",
    reversal: "Reversal",
};

function Kpi({
    title,
    value,
    hint,
    icon: Icon,
    href,
    danger,
}: {
    title: string;
    value: string;
    hint?: string;
    icon: LucideIcon;
    href?: string;
    danger?: boolean;
}) {
    const body = (
        <Card
            className={href ? "transition-colors hover:bg-muted/40" : undefined}
        >
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                    {title}
                </CardTitle>
                <Icon
                    className={`size-4 ${
                        danger ? "text-destructive" : "text-muted-foreground"
                    }`}
                />
            </CardHeader>
            <CardContent>
                <p
                    className={`text-2xl font-semibold ${
                        danger ? "text-destructive" : ""
                    }`}
                >
                    {value}
                </p>
                {hint && (
                    <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
                )}
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{body}</Link> : body;
}

export default function Dashboard({
    scope,
    kpi,
    chart,
    lowStock,
    recent,
    attention,
}: Props) {
    const max = Math.max(
        1,
        ...chart.flatMap((d) => [Number(d.masuk), Number(d.keluar)])
    );
    const totalIn = chart.reduce((sum, d) => sum + Number(d.masuk), 0);
    const totalOut = chart.reduce((sum, d) => sum + Number(d.keluar), 0);

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-6 p-4">
                <p className="text-sm text-muted-foreground">
                    Data untuk:{" "}
                    <span className="font-medium text-foreground">{scope}</span>
                </p>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Kpi
                        title="SKU aktif"
                        value={fmt(kpi.total_sku)}
                        hint="Total produk yang tersedia di gudang"
                        icon={Package}
                        href="/products"
                    />
                    <Kpi
                        title="Total kuantitas stok"
                        value={fmt(kpi.total_quantity)}
                        hint={`${fmt(
                            kpi.stock_rows
                        )} baris stok bersaldo, semua satuan dijumlahkan`}
                        icon={Boxes}
                        href="/inventory"
                    />
                    <Kpi
                        title="Stok rendah"
                        value={fmt(kpi.low_stock)}
                        hint="Saldo sama dengan atau di bawah stok minimum"
                        icon={AlertTriangle}
                        href="/inventory?low=1"
                        danger={kpi.low_stock > 0}
                    />
                    <Kpi
                        title="Perlu tindakan"
                        value={fmt(attention.reduce((s, a) => s + a.count, 0))}
                        hint={
                            attention.length === 0
                                ? "Tidak ada yang menunggu"
                                : "Lihat daftar di bawah"
                        }
                        icon={Layers}
                    />
                </div>

                {attention.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Perlu tindakan
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2">
                            {attention.map((a) => (
                                <Link
                                    key={a.label}
                                    href={a.href}
                                    className="flex items-center justify-between rounded-md border p-3 text-sm hover:bg-muted/40"
                                >
                                    <span>{a.label}</span>
                                    <Badge>{a.count}</Badge>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">
                            Barang masuk dan keluar, 14 hari terakhir
                        </CardTitle>
                        <div className="flex gap-4 text-xs text-muted-foreground">
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-sm bg-primary" />{" "}
                                Masuk {fmt(totalIn)}
                            </span>
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-sm bg-destructive" />{" "}
                                Keluar {fmt(totalOut)}
                            </span>
                            <span>(kuantitas lintas satuan)</span>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div
                            className="flex items-end gap-2"
                            role="img"
                            aria-label="Grafik barang masuk dan keluar 14 hari terakhir"
                        >
                            {chart.map((d) => (
                                <div
                                    key={d.date}
                                    className="flex flex-1 flex-col items-center gap-1"
                                    title={`${d.label}: masuk ${fmt(
                                        d.masuk
                                    )}, keluar ${fmt(d.keluar)}`}
                                >
                                    <div className="flex h-32 w-full items-end justify-center gap-0.5">
                                        <div
                                            className="w-1/2 max-w-3 rounded-t bg-primary"
                                            style={{
                                                height: `${
                                                    (Number(d.masuk) / max) *
                                                    100
                                                }%`,
                                                minHeight:
                                                    Number(d.masuk) > 0 ? 2 : 0,
                                            }}
                                        />
                                        <div
                                            className="w-1/2 max-w-3 rounded-t bg-destructive"
                                            style={{
                                                height: `${
                                                    (Number(d.keluar) / max) *
                                                    100
                                                }%`,
                                                minHeight:
                                                    Number(d.keluar) > 0
                                                        ? 2
                                                        : 0,
                                            }}
                                        />
                                    </div>
                                    <span className="text-[10px] text-muted-foreground">
                                        {d.label}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Stok paling menipis
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Gudang</TableHead>
                                        <TableHead>Produk</TableHead>
                                        <TableHead className="text-right">
                                            Stok
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Minimum
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {lowStock.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="h-16 text-center text-muted-foreground"
                                            >
                                                Tidak ada stok rendah.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        lowStock.map((s) => (
                                            <TableRow key={s.id}>
                                                <TableCell>
                                                    {s.warehouse.code}
                                                </TableCell>
                                                <TableCell>
                                                    <span className="font-mono text-xs">
                                                        {s.product.sku}
                                                    </span>{" "}
                                                    {s.product.name}
                                                </TableCell>
                                                <TableCell className="text-right font-medium text-destructive">
                                                    {fmt(s.quantity)}{" "}
                                                    {s.product.unit?.symbol}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {fmt(s.product.min_stock)}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Mutasi terbaru
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Waktu</TableHead>
                                        <TableHead>Produk</TableHead>
                                        <TableHead>Tipe</TableHead>
                                        <TableHead className="text-right">
                                            Perubahan
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recent.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="h-16 text-center text-muted-foreground"
                                            >
                                                Belum ada mutasi.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        recent.map((m) => {
                                            const delta = Number(
                                                m.quantity_delta
                                            );

                                            return (
                                                <TableRow key={m.id}>
                                                    <TableCell>
                                                        {new Date(
                                                            m.created_at
                                                        ).toLocaleString(
                                                            "id-ID",
                                                            {
                                                                day: "2-digit",
                                                                month: "short",
                                                                hour: "2-digit",
                                                                minute: "2-digit",
                                                            }
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <span className="font-mono text-xs">
                                                            {m.product.sku}
                                                        </span>{" "}
                                                        <span className="text-muted-foreground">
                                                            {m.warehouse.code}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="secondary">
                                                            {TYPE_LABEL[
                                                                m.movement_type
                                                            ] ??
                                                                m.movement_type}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell
                                                        className={`text-right font-medium ${
                                                            delta < 0
                                                                ? "text-destructive"
                                                                : ""
                                                        }`}
                                                    >
                                                        {delta > 0 ? "+" : ""}
                                                        {fmt(delta)}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })
                                    )}
                                </TableBody>
                            </Table>
                            <Link
                                href="/stock-movements"
                                className="mt-3 inline-block text-sm underline-offset-4 hover:underline"
                            >
                                Lihat semua mutasi
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: "Dashboard", href: "/dashboard" }],
};
