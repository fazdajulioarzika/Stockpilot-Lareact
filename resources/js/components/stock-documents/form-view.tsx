import { Head, Link, useForm } from "@inertiajs/react";
import { Plus, Trash2 } from "lucide-react";
import { FormEvent } from "react";

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

import { DocumentMeta, WarehouseOption } from "./types";

type Line = { product_id: string; quantity: string; unit_cost: string };

type FormData = {
    warehouse_id: string;
    supplier_id: string;
    reason: string;
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
    warehouse_id: number;
    supplier_id?: number | null;
    reason?: string | null;
    reference: string | null;
    notes: string | null;
    items: { product_id: number; quantity: string; unit_cost?: string }[];
};

export type FormProps = {
    meta: DocumentMeta;
    document: ExistingDocument | null;
    warehouses: WarehouseOption[];
    products: ProductOption[];
    suppliers?: { id: number; name: string }[];
};

export default function FormView({
    meta,
    document: doc,
    warehouses,
    products,
    suppliers = [],
}: FormProps) {
    const form = useForm<FormData>({
        warehouse_id: doc
            ? String(doc.warehouse_id)
            : warehouses.length === 1
            ? String(warehouses[0].id)
            : "",
        supplier_id: doc?.supplier_id ? String(doc.supplier_id) : "",
        reason: doc?.reason ?? "",
        reference: doc?.reference ?? "",
        notes: doc?.notes ?? "",
        items: doc
            ? doc.items.map((i) => ({
                  product_id: String(i.product_id),
                  quantity: String(Number(i.quantity)),
                  unit_cost: String(Number(i.unit_cost ?? 0)),
              }))
            : [{ product_id: "", quantity: "", unit_cost: "" }],
    });

    const errors = form.errors as Record<string, string | undefined>;
    const chosen = new Set(
        form.data.items.map((i) => i.product_id).filter(Boolean)
    );
    const productById = new Map(products.map((p) => [String(p.id), p]));

    function setLine(index: number, patch: Partial<Line>) {
        form.setData(
            "items",
            form.data.items.map((line, i) =>
                i === index ? { ...line, ...patch } : line
            )
        );
    }

    function addLine() {
        form.setData("items", [
            ...form.data.items,
            { product_id: "", quantity: "", unit_cost: "" },
        ]);
    }

    function removeLine(index: number) {
        form.setData(
            "items",
            form.data.items.filter((_, i) => i !== index)
        );
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        if (doc) {
            form.put(`${meta.basePath}/${doc.id}`);
        } else {
            form.post(meta.basePath);
        }
    }

    const noWarehouse = warehouses.length === 0;

    return (
        <>
            <Head title={doc ? `Ubah ${doc.number}` : `Buat ${meta.title}`} />

            <form
                onSubmit={submit}
                className="flex max-w-4xl flex-col gap-6 p-4"
            >
                <h1 className="text-xl font-semibold">
                    {doc ? `Ubah draft ${doc.number}` : `Buat ${meta.title}`}
                </h1>

                {noWarehouse && (
                    <p className="rounded-md border border-destructive/50 p-3 text-sm text-destructive">
                        Anda belum ditugaskan ke gudang aktif mana pun. Hubungi
                        Admin.
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label>Gudang</Label>
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

                    {meta.type === "receipt" ? (
                        <div className="grid gap-2">
                            <Label>Supplier (opsional)</Label>
                            <Select
                                value={form.data.supplier_id || "none"}
                                onValueChange={(v) =>
                                    form.setData(
                                        "supplier_id",
                                        v === "none" ? "" : v
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Pilih supplier" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Tanpa supplier
                                    </SelectItem>
                                    {suppliers.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
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
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Alasan pengeluaran</Label>
                            <Input
                                id="reason"
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData("reason", e.target.value)
                                }
                                placeholder="Penjualan, rusak, sampel..."
                            />
                            {errors.reason && (
                                <p className="text-sm text-destructive">
                                    {errors.reason}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="reference">Referensi (opsional)</Label>
                        <Input
                            id="reference"
                            value={form.data.reference}
                            onChange={(e) =>
                                form.setData("reference", e.target.value)
                            }
                            placeholder={
                                meta.type === "receipt"
                                    ? "No. surat jalan / PO"
                                    : "No. permintaan / pelanggan"
                            }
                        />
                        {errors.reference && (
                            <p className="text-sm text-destructive">
                                {errors.reference}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
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
                        <Label className="text-base">Item</Label>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={addLine}
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

                        return (
                            <div key={index} className="grid gap-1">
                                <div className="flex items-start gap-2">
                                    <div className="min-w-0 flex-1">
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

                                    <div className="flex w-40 items-center gap-2">
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
                                        <span className="w-10 text-sm text-muted-foreground">
                                            {unit ?? ""}
                                        </span>
                                    </div>
                                    {meta.type === "receipt" && (
                                        <div className="grid w-44 gap-1">
                                            <Input
                                                type="number"
                                                min="0"
                                                step="0.0001"
                                                inputMode="decimal"
                                                placeholder="Harga satuan"
                                                aria-label={`Harga satuan item ${
                                                    index + 1
                                                }`}
                                                value={line.unit_cost}
                                                onChange={(e) =>
                                                    setLine(index, {
                                                        unit_cost:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                            {line.unit_cost !== "" &&
                                                line.quantity !== "" && (
                                                    <span className="text-xs text-muted-foreground">
                                                        ={" "}
                                                        {(
                                                            Number(
                                                                line.unit_cost
                                                            ) *
                                                            Number(
                                                                line.quantity
                                                            )
                                                        ).toLocaleString(
                                                            "id-ID"
                                                        )}
                                                    </span>
                                                )}
                                        </div>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Hapus item ${index + 1}`}
                                        disabled={form.data.items.length === 1}
                                        onClick={() => removeLine(index)}
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
                                    ? `${meta.basePath}/${doc.id}`
                                    : meta.basePath
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
