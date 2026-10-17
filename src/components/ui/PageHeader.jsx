export function PageHeader({ eyebrow, title, description, action }) {
  return <header className="page-header"><div className="page-header-copy"><span className="eyebrow">{eyebrow}</span><h1>{title}</h1>{description && <p>{description}</p>}</div>{action}</header>
}
