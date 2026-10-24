import IndexView, {
    type IndexProps,
} from "@/components/stock-documents/index-view";

export default function StockIssuesIndex(props: IndexProps) {
    return <IndexView {...props} />;
}

StockIssuesIndex.layout = {
    breadcrumbs: [{ title: "Barang Keluar", href: "/stock-issues" }],
};
