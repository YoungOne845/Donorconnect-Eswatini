import { ArrowLeft, Droplets } from 'lucide-react'
import { Link } from 'react-router-dom'
export default function NotFoundPage() { return <div className="not-found"><Droplets size={64} /><span>404</span><h1>This route has run out of blood.</h1><p>The page does not exist or you do not have access to it.</p><Link className="button button-primary" to="/"><ArrowLeft size={17} /> Return home</Link></div> }
