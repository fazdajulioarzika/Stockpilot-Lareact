import FormView, {
    type FormProps,
} from "@/components/stock-documents/form-view";

export default function StockIssuesForm(props: FormProps) {
    return <FormView {...props} />;
}

StockIssuesForm.layout = {
    breadcrumbs: [
        { title: "Barang Keluar", href: "/stock-issues" },
        { title: "Form", href: "#" },
    ],
};
