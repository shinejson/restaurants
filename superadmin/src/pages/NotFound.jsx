import { Link } from 'react-router-dom';
import { Empty } from '../components/ui';

export default function NotFound() {
  return (
    <Empty
      title="That page does not exist"
      body="The link may be old, or the restaurant it pointed at has been deleted."
      action={
        <Link className="btn btn-primary btn-md" to="/">
          Back to the overview
        </Link>
      }
    />
  );
}
