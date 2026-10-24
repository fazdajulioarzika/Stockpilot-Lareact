import ShowView, {
    type ShowProps,
} from "@/components/stock-documents/show-view";

export default function StockIssuesShow(props: ShowProps) {
    return <ShowView {...props} />;
}

StockIssuesShow.layout = {
    breadcrumbs: [
        { title: "Barang Keluar", href: "/stock-issues" },
        { title: "Detail", href: "#" },
    ],
};
