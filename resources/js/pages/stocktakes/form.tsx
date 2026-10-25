import { Head, Link, useForm } from "@inertiajs/react";
import { Plus, Trash2 } from "lucide-react";
import { FormEvent } from "react";

import { WarehouseOption } from "@/components/stock-documents/types";
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

type FormData = {
    warehouse_id: string;
    notes: string;
    product_ids: string[];
};

type ExistingDocument = {
    id: number;
    number: string | null;
    warehouse_id: number;
    notes: string | null;
    product_ids: number[];
};

type Props = {
    document: ExistingDocument | null;
    warehouses: WarehouseOption[];
    products: { id: number; sku: string; name: string }[];
};

export default function StocktakesForm({
    document: doc,
    warehouses,
    products,
}: Props) {
    const form = useForm<FormData>({
        warehouse_id: doc
            ? String(doc.warehouse_id)
            : warehouses.length === 1
            ? String(warehouses[0].id)
            : "",
        notes: doc?.notes ?? "",
        product_ids: doc ? doc.product_ids.map(String) : [],
    });

    const errors = form.errors as Record<string, string | undefined>;
    const chosen = new Set(form.data.product_ids.filter(Boolean));
    const noWarehouse = warehouses.length === 0;

    function setProduct(index: number, value: string) {
        form.setData(
            "product_ids",
            form.data.product_ids.map((p, i) => (i === index ? value : p))
        );
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        // Baris produk yang masih kosong tidak dikirim.
        form.transform((data) => ({
            ...data,
            product_ids: data.product_ids.filter(Boolean),
        }));

        if (doc) {
            form.put(`/stocktakes/${doc.id}`);
        } else {
            form.post("/stocktakes");
        }
    }

    return (
        <>
            <Head title={doc ? `Ubah ${doc.number}` : "Buat Opname"} />

            <form
                onSubmit={submit}
                className="flex max-w-3xl flex-col gap-6 p-4"
            >
                <h1 className="text-xl font-semibold">
                    {doc ? `Ubah draft ${doc.number}` : "Buat Opname"}
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
                        <div>
                            <Label className="text-base">
                                Produk tambahan (opsional)
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                Semua produk yang punya stok otomatis ikut
                                dihitung saat penghitungan dimulai. Tambahkan di
                                sini hanya produk yang stok sistemnya 0 tetapi
                                mungkin ada fisiknya.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData("product_ids", [
                                    ...form.data.product_ids,
                                    "",
                                ])
                            }
                        >
                            <Plus className="mr-1 size-4" /> Tambah produk
                        </Button>
                    </div>

                    {errors.product_ids && (
                        <p className="text-sm text-destructive">
                            {errors.product_ids}
                        </p>
                    )}

                    {form.data.product_ids.map((value, index) => (
                        <div key={index} className="grid gap-1">
                            <div className="flex items-start gap-2">
                                <div className="min-w-0 flex-1">
                                    <Select
                                        value={value}
                                        onValueChange={(v) =>
                                            setProduct(index, v)
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
                                                        String(p.id) === value
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
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Hapus produk ${index + 1}`}
                                    onClick={() =>
                                        form.setData(
                                            "product_ids",
                                            form.data.product_ids.filter(
                                                (_, i) => i !== index
                                            )
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                            {errors[`product_ids.${index}`] && (
                                <p className="text-sm text-destructive">
                                    {errors[`product_ids.${index}`]}
                                </p>
                            )}
                        </div>
                    ))}
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
                            href={doc ? `/stocktakes/${doc.id}` : "/stocktakes"}
                        >
                            Batal
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

StocktakesForm.layout = {
    breadcrumbs: [
        { title: "Stock Opname", href: "/stocktakes" },
        { title: "Form", href: "#" },
    ],
};
