import { Head, Link, useForm } from "@inertiajs/react";
import { Plus, Trash2 } from "lucide-react";
import { FormEvent } from "react";

import {
    formatMoney,
    WarehouseOption,
} from "@/components/stock-documents/types";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";

type Line = { product_id: string; quantity: string; unit_cost: string };

type FormData = {
    supplier_id: string;
    warehouse_id: string;
    expected_date: string;
    reference: string;
    notes: string;
    items: Line[];
};

type ProductOption = {
    id: number;
    sku: string;
    name: string;
    unit: { symbol: string } | null;
};

type ExistingDocument = {
    id: number;
    number: string | null;
    supplier_id: number;
    warehouse_id: number;
    expected_date: string | null;
    reference: string | null;
    notes: string | null;
    items: { product_id: number; quantity: string; unit_cost: string }[];
};

type Props = {
    document: ExistingDocument | null;
    warehouses: WarehouseOption[];
    suppliers: { id: number; name: string }[];
    products: ProductOption[];
};

const emptyLine: Line = { product_id: "", quantity: "", unit_cost: "" };

export default function PurchaseOrdersForm({
    document: doc,
    warehouses,
    suppliers,
    products,
}: Props) {
    const form = useForm<FormData>({
        supplier_id: doc ? String(doc.supplier_id) : "",
        warehouse_id: doc
            ? String(doc.warehouse_id)
            : warehouses.length === 1
            ? String(warehouses[0].id)
            : "",
        expected_date: doc?.expected_date ?? "",
        reference: doc?.reference ?? "",
        notes: doc?.notes ?? "",
        items: doc
            ? doc.items.map((i) => ({
                  product_id: String(i.product_id),
                  quantity: String(Number(i.quantity)),
                  unit_cost: String(Number(i.unit_cost)),
              }))
            : [{ ...emptyLine }],
    });

    const errors = form.errors as Record<string, string | undefined>;
    const chosen = new Set(
        form.data.items.map((i) => i.product_id).filter(Boolean)
    );
    const productById = new Map(products.map((p) => [String(p.id), p]));
    const noWarehouse = warehouses.length === 0;
    const total = form.data.items.reduce(
        (sum, l) =>
            sum + (Number(l.quantity) || 0) * (Number(l.unit_cost) || 0),
        0
    );

    function setLine(index: number, patch: Partial<Line>) {
        form.setData(
            "items",
            form.data.items.map((line, i) =>
                i === index ? { ...line, ...patch } : line
            )
        );
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        if (doc) {
            form.put(`/purchase-orders/${doc.id}`);
        } else {
            form.post("/purchase-orders");
        }
    }

    return (
        <>
            <Head title={doc ? `Ubah ${doc.number}` : "Buat Purchase Order"} />

            <form
                onSubmit={submit}
                className="flex max-w-5xl flex-col gap-6 p-4"
            >
                <h1 className="text-xl font-semibold">
                    {doc ? `Ubah draft ${doc.number}` : "Buat Purchase Order"}
                </h1>

                {noWarehouse && (
                    <p className="rounded-md border border-destructive/50 p-3 text-sm text-destructive">
                        Anda belum ditugaskan ke gudang aktif mana pun. Hubungi
                        Admin.
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label>Supplier</Label>
                        <Select
                            value={form.data.supplier_id}
                            onValueChange={(v) =>
                                form.setData("supplier_id", v)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Pilih supplier" />
                            </SelectTrigger>
                            <SelectContent>
                                {suppliers.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                        {s.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.supplier_id && (
                            <p className="text-sm text-destructive">
                                {errors.supplier_id}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label>Gudang tujuan</Label>
                        <Select
                            value={form.data.warehouse_id}
                            onValueChange={(v) =>
                                form.setData("warehouse_id", v)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Pilih gudang" />
                            </SelectTrigger>
                            <SelectContent>
                                {warehouses.map((w) => (
                                    <SelectItem key={w.id} value={String(w.id)}>
                                        {w.code} - {w.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.warehouse_id && (
                            <p className="text-sm text-destructive">
                                {errors.warehouse_id}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="expected_date">
                            Perkiraan tiba (opsional)
                        </Label>
                        <Input
                            id="expected_date"
                            type="date"
                            value={form.data.expected_date}
                            onChange={(e) =>
                                form.setData("expected_date", e.target.value)
                            }
                        />
                        {errors.expected_date && (
                            <p className="text-sm text-destructive">
                                {errors.expected_date}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reference">Referensi (opsional)</Label>
                        <Input
                            id="reference"
                            value={form.data.reference}
                            onChange={(e) =>
                                form.setData("reference", e.target.value)
                            }
                            placeholder="No. penawaran supplier"
                        />
                        {errors.reference && (
                            <p className="text-sm text-destructive">
                                {errors.reference}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="notes">Catatan (opsional)</Label>
                        <Input
                            id="notes"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData("notes", e.target.value)
                            }
                        />
                        {errors.notes && (
                            <p className="text-sm text-destructive">
                                {errors.notes}
                            </p>
                        )}
                    </div>
                </div>

                <div className="grid gap-3">
                    <div className="flex items-center justify-between">
                        <Label className="text-base">Item pesanan</Label>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData("items", [
                                    ...form.data.items,
                                    { ...emptyLine },
                                ])
                            }
                        >
                            <Plus className="mr-1 size-4" /> Tambah item
                        </Button>
                    </div>

                    {errors.items && (
                        <p className="text-sm text-destructive">
                            {errors.items}
                        </p>
                    )}

                    {form.data.items.map((line, index) => {
                        const unit = productById.get(line.product_id)?.unit
                            ?.symbol;
                        const lineTotal =
                            (Number(line.quantity) || 0) *
                            (Number(line.unit_cost) || 0);

                        return (
                            <div key={index} className="grid gap-1">
                                <div className="flex flex-wrap items-start gap-2">
                                    <div className="min-w-56 flex-1">
                                        <Select
                                            value={line.product_id}
                                            onValueChange={(v) =>
                                                setLine(index, {
                                                    product_id: v,
                                                })
                                            }
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih produk" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {products
                                                    .filter(
                                                        (p) =>
                                                            !chosen.has(
                                                                String(p.id)
                                                            ) ||
                                                            String(p.id) ===
                                                                line.product_id
                                                    )
                                                    .map((p) => (
                                                        <SelectItem
                                                            key={p.id}
                                                            value={String(p.id)}
                                                        >
                                                            {p.sku} - {p.name}
                                                        </SelectItem>
                                                    ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="flex w-36 items-center gap-2">
                                        <Input
                                            type="number"
                                            min="0"
                                            step="0.001"
                                            inputMode="decimal"
                                            placeholder="Jumlah"
                                            aria-label={`Jumlah item ${
                                                index + 1
                                            }`}
                                            value={line.quantity}
                                            onChange={(e) =>
                                                setLine(index, {
                                                    quantity: e.target.value,
                                                })
                                            }
                                        />
                                        <span className="w-8 text-sm text-muted-foreground">
                                            {unit ?? ""}
                                        </span>
                                    </div>

                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        inputMode="decimal"
                                        placeholder="Harga satuan"
                                        aria-label={`Harga satuan item ${
                                            index + 1
                                        }`}
                                        className="w-40"
                                        value={line.unit_cost}
                                        onChange={(e) =>
                                            setLine(index, {
                                                unit_cost: e.target.value,
                                            })
                                        }
                                    />

                                    <span className="w-32 self-center text-right text-sm text-muted-foreground">
                                        {formatMoney(lineTotal)}
                                    </span>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Hapus item ${index + 1}`}
                                        disabled={form.data.items.length === 1}
                                        onClick={() =>
                                            form.setData(
                                                "items",
                                                form.data.items.filter(
                                                    (_, i) => i !== index
                                                )
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                                {errors[`items.${index}.product_id`] && (
                                    <p className="text-sm text-destructive">
                                        {errors[`items.${index}.product_id`]}
                                    </p>
                                )}
                                {errors[`items.${index}.quantity`] && (
                                    <p className="text-sm text-destructive">
                                        {errors[`items.${index}.quantity`]}
                                    </p>
                                )}
                                {errors[`items.${index}.unit_cost`] && (
                                    <p className="text-sm text-destructive">
                                        {errors[`items.${index}.unit_cost`]}
                                    </p>
                                )}
                            </div>
                        );
                    })}

                    <p className="text-right text-sm font-medium">
                        Total: {formatMoney(total)}
                    </p>
                </div>

                <div className="flex gap-2">
                    <Button
                        type="submit"
                        disabled={form.processing || noWarehouse}
                    >
                        {form.processing ? "Menyimpan..." : "Simpan draft"}
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <Link
                            href={
                                doc
                                    ? `/purchase-orders/${doc.id}`
                                    : "/purchase-orders"
                            }
                        >
                            Batal
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

PurchaseOrdersForm.layout = {
    breadcrumbs: [
        { title: "Purchase Order", href: "/purchase-orders" },
        { title: "Form", href: "#" },
    ],
};
