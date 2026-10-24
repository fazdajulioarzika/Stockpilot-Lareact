import FormView, {
    type FormProps,
} from "@/components/stock-documents/form-view";

export default function GoodsReceiptsForm(props: FormProps) {
    return <FormView {...props} />;
}

GoodsReceiptsForm.layout = {
    breadcrumbs: [
        { title: "Barang Masuk", href: "/goods-receipts" },
        { title: "Form", href: "#" },
    ],
};
