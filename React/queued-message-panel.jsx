var QueuedMessageListItem = React.createClass({
	handleClick: function(id){
		this.props.onDelete(id);
	},
	
	render: function(){
		return (
			<li className="list-group-item" id={"queued-"+ this.props.id}>
				<div className="media-left">
					<span className="icon-wrap icon-wrap-xs icon-circle alert-icon bg-info">
						<i className={"fa fa-lg fa-" + (this.props.channel === 'Email' ? 'envelope' : 'mobile')}></i>
					</span>
				</div>
				<div className="media-body">
					<span className="col-sm-3">
						<strong>From:</strong> {this.props.from}<br/>
						<strong>To:</strong> {this.props.to}<br/>
					</span>
					<span className="col-sm-8">
						<strong>{this.props.subject}</strong><br/>
						<span dangerouslySetInnerHTML={{__html: this.props.message}}></span>
					</span>
					<span className="col-sm-1">
						<button className="btn btn-md btn-danger btn-labeled fa fa-ban pull-right cancel-message"  onClick={this.handleClick.bind(this, this.props.id)} type="submit">Remove</button>
					</span>
				</div>
			</li>
		);
	}
});

var QueuedMessageContent = React.createClass({
	render: function() {

		if (this.props.content.length){
			var msgs = this.props.content.map(function(item, i){
				return <QueuedMessageListItem key={item.id} onDelete={this.props.onDelete} {...item} />
			}, this );
		} 
		else {
			var msgs = 'NOTHING IN THE QUEUE';
		}

		return (
			<div className="panel-body">
				<ul className="list-group">
					{msgs}
				</ul>
			</div>
		);
	}
});

var QueuedMessageHeader = React.createClass({
	render: function() {
		return (
			<div className="panel-heading">
				<h3 className="panel-title">Queued Messages</h3>
			</div>
		);
	}
});

var QueuedMessageBox = React.createClass({
	getInitialState: function() {
		return {
			content: []
		};
	},

	componentDidMount: function () {
		this.getContent();
		setInterval(this.getContent, 5000);
	},

	componentDidUpdate: function (){
		$(".nano").nanoScroller();
	},

	onDelete: function(id) {

		$.ajax({
			url: '/messages/queued/'+ id,
			dataType: 'json',
			cache: false,
			type: 'DELETE',

			beforeSend: function () {
				$('#queued-'+ id).fadeOut();
			}.bind(this),

			success: function(data) {
				// If there was an issue deleting the message display it again
				if(!data.success){
					$('#queued-'+ id).fadeIn();
				}
			}.bind(this),

			error: function(xhr, status, err) {
				$('#queued-'+ id).fadeIn();

				console.error(this.props.url, status, err.toString());
				this.setState({
					response: {
						type: 'error',
						message: 'There was an issue getting messages in the queue. Please contact the Administrator!'
					}
				});
			}.bind(this)
		});
	},

	getContent: function (type){

		var url = '/messages/queued/';
		$.ajax({
			url: url,
			dataType: 'json',
			cache: false,
			type: 'GET',

			success: function(data) {
				this.setState({
					content: data
				});
			}.bind(this),

			error: function(xhr, status, err) {
				console.error(this.props.url, status, err.toString());
				this.setState({
					response: {
						type: 'error',
						message: 'There was an issue getting messages in the queue. Please contact the Administrator!'
					}
				});
			}.bind(this)
		});
	},

	render: function() {
		return (
			<div className="panel panel-dark">
				<QueuedMessageHeader />
				<div className="nano">
					<div className="nano-content">
						<QueuedMessageContent content={this.state.content} onDelete={this.onDelete} />
					</div>
				</div>
			</div>
		);
	}
});

ReactDOM.render(
	<QueuedMessageBox />,
	document.getElementById('queuedMessages')
);