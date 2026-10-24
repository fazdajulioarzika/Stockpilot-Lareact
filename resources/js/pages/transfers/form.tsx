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
import { WarehouseOption } from "@/components/stock-documents/types";

type Line = { product_id: string; quantity: string };

type FormData = {
    source_warehouse_id: string;
    destination_warehouse_id: string;
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
    source_warehouse_id: number;
    destination_warehouse_id: number;
    reference: string | null;
    notes: string | null;
    items: { product_id: number; quantity: string }[];
};

type Props = {
    document: ExistingDocument | null;
    sources: WarehouseOption[];
    destinations: WarehouseOption[];
    products: ProductOption[];
};

export default function TransfersForm({
    document: doc,
    sources,
    destinations,
    products,
}: Props) {
    const form = useForm<FormData>({
        source_warehouse_id: doc
            ? String(doc.source_warehouse_id)
            : sources.length === 1
            ? String(sources[0].id)
            : "",
        destination_warehouse_id: doc
            ? String(doc.destination_warehouse_id)
            : "",
        reference: doc?.reference ?? "",
        notes: doc?.notes ?? "",
        items: doc
            ? doc.items.map((i) => ({
                  product_id: String(i.product_id),
                  quantity: String(Number(i.quantity)),
              }))
            : [{ product_id: "", quantity: "" }],
    });

    const errors = form.errors as Record<string, string | undefined>;
    const chosen = new Set(
        form.data.items.map((i) => i.product_id).filter(Boolean)
    );
    const productById = new Map(products.map((p) => [String(p.id), p]));
    const noSource = sources.length === 0;

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
            form.put(`/transfers/${doc.id}`);
        } else {
            form.post("/transfers");
        }
    }

    return (
        <>
            <Head title={doc ? `Ubah ${doc.number}` : "Buat Transfer"} />

            <form
                onSubmit={submit}
                className="flex max-w-4xl flex-col gap-6 p-4"
            >
                <h1 className="text-xl font-semibold">
                    {doc ? `Ubah draft ${doc.number}` : "Buat Transfer"}
                </h1>

                {noSource && (
                    <p className="rounded-md border border-destructive/50 p-3 text-sm text-destructive">
                        Anda belum ditugaskan ke gudang aktif mana pun. Hubungi
                        Admin.
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label>Gudang sumber</Label>
                        <Select
                            value={form.data.source_warehouse_id}
                            onValueChange={(v) =>
                                form.setData("source_warehouse_id", v)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Pilih gudang sumber" />
                            </SelectTrigger>
                            <SelectContent>
                                {sources.map((w) => (
                                    <SelectItem key={w.id} value={String(w.id)}>
                                        {w.code} - {w.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.source_warehouse_id && (
                            <p className="text-sm text-destructive">
                                {errors.source_warehouse_id}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label>Gudang tujuan</Label>
                        <Select
                            value={form.data.destination_warehouse_id}
                            onValueChange={(v) =>
                                form.setData("destination_warehouse_id", v)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Pilih gudang tujuan" />
                            </SelectTrigger>
                            <SelectContent>
                                {destinations
                                    .filter(
                                        (w) =>
                                            String(w.id) !==
                                            form.data.source_warehouse_id
                                    )
                                    .map((w) => (
                                        <SelectItem
                                            key={w.id}
                                            value={String(w.id)}
                                        >
                                            {w.code} - {w.name}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                        {errors.destination_warehouse_id && (
                            <p className="text-sm text-destructive">
                                {errors.destination_warehouse_id}
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
                            placeholder="No. permintaan transfer"
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
                            onClick={() =>
                                form.setData("items", [
                                    ...form.data.items,
                                    { product_id: "", quantity: "" },
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
                            </div>
                        );
                    })}
                </div>

                <div className="flex gap-2">
                    <Button
                        type="submit"
                        disabled={form.processing || noSource}
                    >
                        {form.processing ? "Menyimpan..." : "Simpan draft"}
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <Link
                            href={doc ? `/transfers/${doc.id}` : "/transfers"}
                        >
                            Batal
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

TransfersForm.layout = {
    breadcrumbs: [
        { title: "Transfer", href: "/transfers" },
        { title: "Form", href: "#" },
    ],
};
